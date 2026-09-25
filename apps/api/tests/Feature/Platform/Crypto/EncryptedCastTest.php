<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Crypto\EncryptedCastTest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;
use Kern\Platform\KeyManagement\SubjectDekService;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\Feature\Platform\Crypto\Fixtures\EncryptedNoteA;
use Tests\Feature\Platform\Crypto\Fixtures\EncryptedNoteB;

/**
 * Model::getKey() is untyped (effectively mixed) — every fixture here uses a string UUID
 * primary key, so this narrows it once instead of repeating an is_string() guard at every call
 * site.
 */
function keyOf(Model $model): string
{
    $key = $model->getKey();

    if (! is_string($key)) {
        throw new RuntimeException('Expected a string primary key.');
    }

    return $key;
}

/**
 * DB::table(...)->value(...) returns mixed; every column read in this file is a text column
 * that is either a string envelope or null.
 */
function stringColumn(string $table, string $id, string $column): string
{
    $value = DB::table($table)->where('id', $id)->value($column);

    if (! is_string($value)) {
        throw new RuntimeException("Expected column {$table}.{$column} to be a string.");
    }

    return $value;
}

beforeEach(function (): void {
    Schema::dropIfExists('encrypted_notes_a');
    Schema::dropIfExists('encrypted_notes_b');

    foreach (['encrypted_notes_a', 'encrypted_notes_b'] as $table) {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tax_subject_id');
            $blueprint->text('field_one')->nullable();
            $blueprint->text('field_two')->nullable();
        });
    }

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

afterEach(function (): void {
    Schema::dropIfExists('encrypted_notes_a');
    Schema::dropIfExists('encrypted_notes_b');
});

it('round-trips an encrypted field through save and a fresh read from the database', function (): void {
    $note = EncryptedNoteA::create([
        'tax_subject_id' => '11111111-1111-4111-8111-111111111111',
        'field_one' => '1900101234567',
    ]);

    $reloaded = EncryptedNoteA::query()->findOrFail(keyOf($note));

    expect($reloaded->field_one)->toBe('1900101234567');
});

it('never stores the plaintext value anywhere in the raw database column', function (): void {
    $plaintext = 'a-very-recognisable-cnp-1900101234567';

    $note = EncryptedNoteA::create([
        'tax_subject_id' => '11111111-1111-4111-8111-111111111111',
        'field_one' => $plaintext,
    ]);

    $rawFieldOne = stringColumn('encrypted_notes_a', keyOf($note), 'field_one');

    expect($rawFieldOne)->not->toContain($plaintext)
        ->and((string) base64_decode($rawFieldOne, true))->not->toContain($plaintext);
});

it('leaves a null field as null, not an envelope', function (): void {
    $note = EncryptedNoteA::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => null]);

    $raw = DB::table('encrypted_notes_a')->where('id', keyOf($note))->first();
    $reloaded = EncryptedNoteA::query()->findOrFail(keyOf($note));

    expect($raw)->not->toBeNull()
        ->and($raw->field_one ?? null)->toBeNull()
        ->and($reloaded->field_one)->toBeNull();
});

describe('AAD binding — a ciphertext copied elsewhere must fail to decrypt', function (): void {
    it('fails when moved to a different row', function (): void {
        $noteOne = EncryptedNoteA::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => 'value-one']);
        $noteTwo = EncryptedNoteA::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => 'value-two']);

        $envelopeFromNoteOne = stringColumn('encrypted_notes_a', keyOf($noteOne), 'field_one');
        DB::table('encrypted_notes_a')->where('id', keyOf($noteTwo))->update(['field_one' => $envelopeFromNoteOne]);

        expect(fn () => EncryptedNoteA::query()->findOrFail(keyOf($noteTwo))->field_one)
            ->toThrow(EnvelopeException::class);
    });

    it('fails when moved to a different column on the same row', function (): void {
        $note = EncryptedNoteA::create([
            'tax_subject_id' => '11111111-1111-4111-8111-111111111111',
            'field_one' => 'value-one',
            'field_two' => 'value-two',
        ]);

        $envelopeFromFieldOne = stringColumn('encrypted_notes_a', keyOf($note), 'field_one');
        DB::table('encrypted_notes_a')->where('id', keyOf($note))->update(['field_two' => $envelopeFromFieldOne]);

        expect(fn () => EncryptedNoteA::query()->findOrFail(keyOf($note))->field_two)
            ->toThrow(EnvelopeException::class);
    });

    it('fails when moved to a different table', function (): void {
        $noteA = EncryptedNoteA::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => 'value']);
        $noteB = EncryptedNoteB::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => 'placeholder']);

        $envelopeFromNoteA = stringColumn('encrypted_notes_a', keyOf($noteA), 'field_one');
        DB::table('encrypted_notes_b')->where('id', keyOf($noteB))->update(['field_one' => $envelopeFromNoteA]);

        expect(fn () => EncryptedNoteB::query()->findOrFail(keyOf($noteB))->field_one)
            ->toThrow(EnvelopeException::class);
    });

    it('fails when moved to a different tax subject', function (): void {
        $subjectOneNote = EncryptedNoteA::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => 'value']);
        $subjectTwoNote = EncryptedNoteA::create(['tax_subject_id' => '22222222-2222-4222-8222-222222222222', 'field_one' => 'placeholder']);

        $envelopeFromSubjectOne = stringColumn('encrypted_notes_a', keyOf($subjectOneNote), 'field_one');
        DB::table('encrypted_notes_a')->where('id', keyOf($subjectTwoNote))->update(['field_one' => $envelopeFromSubjectOne]);

        expect(fn () => EncryptedNoteA::query()->findOrFail(keyOf($subjectTwoNote))->field_one)
            ->toThrow(EnvelopeException::class);
    });
});

it('lets fields be assigned in any order without breaking the AAD binding (FillsSubjectForeignKeyFirst)', function (): void {
    // tax_subject_id appears AFTER the encrypted field in this array — if the trait did not
    // reorder assignment, this would silently bind field_one's AAD to an empty subject id.
    $note = new EncryptedNoteA(['field_one' => 'value', 'tax_subject_id' => '11111111-1111-4111-8111-111111111111']);
    $note->save();

    expect(EncryptedNoteA::query()->findOrFail(keyOf($note))->field_one)->toBe('value');
});

it('sanity-checks that the raw column really is this project\'s envelope format', function (): void {
    $note = EncryptedNoteA::create(['tax_subject_id' => '11111111-1111-4111-8111-111111111111', 'field_one' => 'value']);

    $raw = stringColumn('encrypted_notes_a', keyOf($note), 'field_one');

    expect(EnvelopeCipher::keyVersionOf($raw))->toBe(1);
});
