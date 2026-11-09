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
 * `POST /api/v1/legislation/watches` — a search a person asks to keep, from the "Add a law" dialog: the
 * words of a title and an act type, every year from the first given, run every so many hours (one of
 * the intervals offered). It keeps every act its search finds — a pattern is for a package to write — and
 * runs at the next hourly run. Recorded in the audit chain: it reaches out to the portal, on a clock.
 */
final class CreateLawWatchController
{
    public function __invoke(Request $request, AuditChainService $audit): JsonResponse
    {
        $settings = WatchSettings::read(config());
        $asked = $request->validate([
            'search_words' => ['required', 'string', 'min:3', 'max:200'],
            // The portal's own type key, as the dialog sends it: this package does not know which types an
            // application reads.
            'act_type' => ['required', 'string', 'regex:/\A[A-Z]{2,32}\z/'],
            'first_year' => ['required', 'integer', 'min:' . config()->integer('legislation.form.min_year'), 'max:' . date('Y')],
            'every_hours' => ['required', 'integer', Rule::in($settings->intervals)],
        ]);
        $actor = $request->user()?->getAuthIdentifier();
        $actor = is_scalar($actor) ? (string) $actor : null;

        // A few searches a person keeps, not a crawl of the portal.
        if (LawWatch::query()->where('created_by', $actor)->count() >= $settings->maxPerPerson) {
            return response()->json(['reason' => 'TOO_MANY_WATCHES', 'message' => "A person keeps {$settings->maxPerPerson} watches at most."], 422);
        }

        $watch = LawWatch::query()->create([
            ...$asked,
            'search_words' => trim((string) $asked['search_words']),
            'created_by' => $actor,
            'enabled' => true,
            'next_run_at' => now(),
        ]);

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $watch->created_by,
            eventType: 'LawWatchCreated',
            resourceType: 'LawWatch',
            resourceId: $watch->id,
            payloadHash: $audit->hashPayload(['search_words' => $watch->search_words, 'act_type' => $watch->act_type, 'every_hours' => $watch->every_hours]),
        ));

        return response()->json(LawWatchView::of($watch, $watch->created_by), 201);
    }
}
