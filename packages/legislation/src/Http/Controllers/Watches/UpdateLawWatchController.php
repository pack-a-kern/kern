<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Watches\WatchSettings;

/**
 * `PATCH /api/v1/legislation/watches/{id}` — paused or resumed; a person's own watch also run more or less
 * often. A package's watch keeps the interval its configuration gives it: only pausing it is the screen's.
 * A person's watch is changed by that person alone. Every change is recorded in the audit chain — pausing a
 * package's watch stops what it reads, and that is not done without a trace.
 */
final class UpdateLawWatchController
{
    public function __invoke(Request $request, string $watch, AuditChainService $audit): JsonResponse
    {
        $found = LawWatch::query()->findOrFail($watch);
        $actor = $request->user()?->getAuthIdentifier();
        $actor = is_scalar($actor) ? (string) $actor : null;

        abort_if(! $found->isDeclared() && $found->created_by !== $actor, 403);

        $changed = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'every_hours' => [$found->isDeclared() ? 'prohibited' : 'sometimes', 'integer', Rule::in(WatchSettings::read(config())->intervals)],
        ]);

        $found->fill($changed)->save();

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actor,
            eventType: 'LawWatchChanged',
            resourceType: 'LawWatch',
            resourceId: $found->id,
            payloadHash: $audit->hashPayload(['key' => $found->key, 'enabled' => $found->enabled, 'every_hours' => $found->every_hours]),
        ));

        return response()->json(LawWatchView::of($found, $actor));
    }
}
