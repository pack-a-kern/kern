<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Integration\RequirementsReadinessTest;

use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Requirements\LegislationRequirements;
use Kern\Legislation\Requirements\NeedResolvers;
use Kern\Legislation\Requirements\ReadinessReport;
use Kern\Legislation\Requirements\RequirementTier;
use RuntimeException;

/**
 * ADR 0027 — a package declares the legislation it requires, and the readiness query resolves each
 * need through the lookup that already exists, rolling the answers up to whether the install is
 * bootstrapped and each feature available. This exercises the registry, the `law`/`watch` resolvers
 * this package owns, the roll-up, and that a malformed or unresolvable declaration fails loudly.
 */

/** @param array<string, mixed> $declared */
function readiness(array $declared): array
{
    config(['legislation.requirements.declared' => $declared]);

    return (new ReadinessReport(new LegislationRequirements(config()), app(NeedResolvers::class)))();
}

/** A law in the library, in force now, filed under an identifier. */
function lawInForce(string $identifier): SourceDocument
{
    $law = SourceDocument::factory()->create(['document_type' => 'LAW', 'external_identifier' => $identifier]);
    SourceDocumentVersion::factory()->create([
        'source_document_id' => $law->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);

    return $law;
}

/** A declared watch that has read one law. */
function watchWithLaw(string $key): void
{
    $watch = LawWatch::query()->create([
        'key' => $key,
        'declared_by' => 'kern/example',
        'search_words' => 'exemplu',
        'act_type' => 'ORDIN',
        'first_year' => 2024,
        'every_hours' => 168,
        'enabled' => true,
        'next_run_at' => now(),
    ]);
    SourceDocument::factory()->create(['document_type' => 'ORDIN', 'found_by_watch_id' => $watch->id]);
}

it('reads declared requirements into typed definitions, in order', function (): void {
    config(['legislation.requirements.declared' => [
        'example.cod' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['law' => 'LEGE 1/2000']],
        'example.bonds' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders']],
    ]]);

    $definitions = (new LegislationRequirements(config()))->all();

    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->key)->toBe('example.cod')
        ->and($definitions[0]->tier)->toBe(RequirementTier::REQUIRED)
        ->and($definitions[0]->feature)->toBeNull()
        ->and($definitions[0]->needs[0]->kind)->toBe('law')
        ->and($definitions[0]->needs[0]->argument)->toBe('LEGE 1/2000')
        ->and($definitions[1]->tier)->toBe(RequirementTier::FEATURE)
        ->and($definitions[1]->feature)->toBe('example.bonds');
});

it('fails loudly on a malformed requirement, naming it', function (): void {
    config(['legislation.requirements.declared' => [
        'example.bad' => ['declared_by' => 'kern/example', 'tier' => 'nonsense', 'needs' => ['law' => 'LEGE 1/2000']],
    ]]);

    expect(fn (): array => (new LegislationRequirements(config()))->all())
        ->toThrow(RuntimeException::class, 'example.bad');
});

it('fails loudly when a feature requirement names no feature', function (): void {
    config(['legislation.requirements.declared' => [
        'example.f' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'needs' => ['watch' => 'example.orders']],
    ]]);

    expect(fn (): array => (new LegislationRequirements(config()))->all())
        ->toThrow(RuntimeException::class, 'example.f');
});

it('the law need is unmet when the law is absent, undated, or out of force', function (): void {
    $absent = readiness(['example.cod' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['law' => 'LEGE 1/2000']]]);
    expect($absent['is_bootstrapped'])->toBeFalse()
        ->and($absent['requirements'][0]['needs'][0]['met'])->toBeFalse();

    // Read but not yet dated by a human — no effective_from — so not in force, so not present.
    $law = SourceDocument::factory()->create(['document_type' => 'LAW', 'external_identifier' => 'LEGE 1/2000']);
    SourceDocumentVersion::factory()->create(['source_document_id' => $law->id, 'effective_from' => null]);

    $undated = readiness(['example.cod' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['law' => 'LEGE 1/2000']]]);
    expect($undated['is_bootstrapped'])->toBeFalse();
});

it('the law need is met once a dated version is in force', function (): void {
    lawInForce('LEGE 227/2015');

    $report = readiness(['example.cod' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['law' => 'LEGE 227/2015']]]);

    expect($report['is_bootstrapped'])->toBeTrue()
        ->and($report['requirements'][0]['met'])->toBeTrue();
});

it('the watch need is met once the watch has read a law', function (): void {
    $before = readiness(['example.bonds' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders']]]);
    expect($before['features']['example.bonds']['available'])->toBeFalse();

    watchWithLaw('example.orders');

    $after = readiness(['example.bonds' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders']]]);
    expect($after['features']['example.bonds']['available'])->toBeTrue();
});

it('bootstraps on the required tier while a feature stays unavailable, and gates independently', function (): void {
    lawInForce('LEGE 227/2015'); // required met; the bonds watch has read nothing.

    $report = readiness([
        'example.cod' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['law' => 'LEGE 227/2015']],
        'example.bonds' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders']],
    ]);

    expect($report['is_bootstrapped'])->toBeTrue()
        ->and($report['features']['example.bonds']['available'])->toBeFalse();
});

it('a feature gated by two requirements is available only when both are met', function (): void {
    lawInForce('LEGE 227/2015');
    watchWithLaw('example.orders.a'); // only one of the two feature requirements is satisfied

    $report = readiness([
        'example.bonds.a' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders.a']],
        'example.bonds.b' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders.b']],
    ]);

    expect($report['features']['example.bonds']['available'])->toBeFalse();
});

it('fails loudly when a need names a kind no package resolves', function (): void {
    expect(fn (): array => readiness([
        'example.x' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['nobody' => 'anything']],
    ]))->toThrow(RuntimeException::class, 'nobody');
});

it('attaches the watch run state as detail, while a law need carries none', function (): void {
    lawInForce('LEGE 227/2015');
    watchWithLaw('example.orders'); // read a law, no run on the queue

    $report = readiness([
        'example.cod' => ['declared_by' => 'kern/example', 'tier' => 'required', 'needs' => ['law' => 'LEGE 227/2015']],
        'example.bonds' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders']],
    ]);

    // A bare bool resolver (the law need) reports no detail; the watch resolver reports its run state.
    expect($report['requirements'][0]['needs'][0])->not->toHaveKey('detail')
        ->and($report['requirements'][1]['needs'][0]['detail'])->toBe(['running' => false]);
});

it('reports a watch as running while a run is on the queue', function (): void {
    LawWatch::query()->create([
        'key' => 'example.orders',
        'declared_by' => 'kern/example',
        'search_words' => 'exemplu',
        'act_type' => 'ORDIN',
        'first_year' => 2024,
        'every_hours' => 168,
        'enabled' => true,
        'next_run_at' => now(),
        'run_requested_at' => now(),
    ]);

    $report = readiness([
        'example.bonds' => ['declared_by' => 'kern/example', 'tier' => 'feature', 'feature' => 'example.bonds', 'needs' => ['watch' => 'example.orders']],
    ]);

    // Nothing read yet, so the need is unmet, but the screen can show it as fetching.
    expect($report['requirements'][0]['needs'][0]['met'])->toBeFalse()
        ->and($report['requirements'][0]['needs'][0]['detail'])->toBe(['running' => true]);
});
