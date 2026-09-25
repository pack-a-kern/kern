<?php

declare(strict_types=1);

namespace Kern\Audit\Queries;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail for the admin screen.
 *
 * Two properties of `audit_events` shape what this can return, and both are deliberate:
 *
 *  - **There is no payload.** The table stores `payload_hash`, never the payload itself
 *    (`BLUEPRINT.md` §4.8), so an audit row can prove *that* a change happened and that nobody
 *    altered the record of it, without becoming a second copy of the data it describes — which
 *    would defeat the encryption everywhere else. The screen therefore shows the hash.
 *  - **There is no IP address and no severity column.** Neither is modelled. `security_events`
 *    carries request context separately; inventing a severity here by classifying event types in
 *    the UI would put a judgement into an evidentiary record that the record does not make.
 *
 * ## Paging an append-only log by page number
 *
 * The screen offers numbered pages like every other list here, which needs a total and an offset —
 * and an offset is exactly what a growing log gets wrong, because a row appended between two
 * requests shifts every later page by one. So each run of paging is frozen to an **anchor**: the
 * newest `occurred_at` at the moment the filters were applied. Every page, and the total, are
 * computed within `occurred_at <= anchor`, so the window a person is reading through does not move
 * while they read it, and events arriving meanwhile simply are not in it. The client sends the
 * anchor back with each page and drops it when a filter changes.
 *
 * Ordered by `occurred_at`, not by `sequence`: `sequence` is per chain (`chain_key`), so ordering
 * across chains by it interleaves unrelated counters. `id` breaks ties, so the order is total.
 */
final readonly class AuditEventQuery
{
    /**
     * @param  array{q?: string|null, event_type?: string|null, subject_id?: string|null, since?: string|null, anchor?: string|null}  $filters
     * @return array{events: list<array<string, mixed>>, total: int, page: int, per_page: int, anchor: string|null}
     */
    public function page(array $filters, int $perPage, int $page): array
    {
        // Fixed before anything is counted or read, so the total and every page describe the same
        // set of rows — see the class docblock.
        $anchor = ($filters['anchor'] ?? null) ?? $this->newestOccurredAt($filters);
        $filters['anchor'] = $anchor;

        $total = $this->filtered($filters)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max($page, 1), $pages);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->filtered($filters)
            ->orderByDesc('a.occurred_at')
            ->orderByDesc('a.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return [
            'events' => array_map($this->present(...), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'anchor' => $anchor,
        ];
    }

    /**
     * The newest matching event's timestamp, which becomes the anchor for a run of paging.
     *
     * Null when nothing matches — there is then no window to freeze, and the filtered query leaves
     * the bound off entirely.
     *
     * @param  array{q?: string|null, event_type?: string|null, subject_id?: string|null, since?: string|null, anchor?: string|null}  $filters
     */
    private function newestOccurredAt(array $filters): ?string
    {
        $newest = $this->filtered($filters)->max('a.occurred_at');

        return is_scalar($newest) ? (string) $newest : null;
    }

    /**
     * @return array{events_total: int, events_24h: int, actors_24h: int, event_types: list<string>}
     */
    public function summary(): array
    {
        $since = now('UTC')->subDay();

        $counts = (array) DB::table('audit_events')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where occurred_at >= ?) as recent', [$since])
            ->selectRaw('count(distinct actor_id) filter (where occurred_at >= ?) as actors', [$since])
            ->first();

        $types = DB::table('audit_events')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();

        return [
            'events_total' => (int) $this->num($counts, 'total'),
            'events_24h' => (int) $this->num($counts, 'recent'),
            'actors_24h' => (int) $this->num($counts, 'actors'),
            'event_types' => array_values(array_filter($types, is_string(...))),
        ];
    }

    /**
     * @param  array{q?: string|null, event_type?: string|null, subject_id?: string|null, since?: string|null, anchor?: string|null}  $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        $query = DB::table('audit_events as a')
            ->select([
                'a.id', 'a.chain_key', 'a.sequence', 'a.subject_id', 'a.actor_id',
                'a.event_type', 'a.resource_type', 'a.resource_id', 'a.occurred_at',
                'a.payload_hash', 'a.previous_hash', 'a.event_hash', 'a.signature_key_version',
            ]);

        if (($filters['event_type'] ?? null) !== null) {
            $query->where('a.event_type', $filters['event_type']);
        }

        if (($filters['subject_id'] ?? null) !== null) {
            $query->where('a.subject_id', $filters['subject_id']);
        }

        if (($filters['since'] ?? null) !== null) {
            $query->where('a.occurred_at', '>=', $filters['since']);
        }

        if (($filters['anchor'] ?? null) !== null) {
            $query->where('a.occurred_at', '<=', $filters['anchor']);
        }

        $term = $filters['q'] ?? null;

        if ($term !== null && $term !== '') {
            // Only over columns that are plaintext by design. Actor and subject are opaque ids
            // here; searching them by name would mean decrypting every row to compare, which is
            // both slow and the sort of bulk decryption §4.3 exists to avoid.
            $escaped = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
            $query->where(function (QueryBuilder $inner) use ($escaped): void {
                $inner->where('a.event_type', 'ilike', $escaped)
                    ->orWhere('a.resource_type', 'ilike', $escaped)
                    ->orWhere('a.resource_id', 'ilike', $escaped);
            });
        }

        return $query;
    }

    /**
     * The query builder hands back `stdClass`, which PHPStan cannot introspect; this narrows each
     * column once, at the boundary, instead of casting `mixed` at every use.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => $this->str($row, 'id'),
            'chain_key' => $this->str($row, 'chain_key'),
            'sequence' => (int) $this->num($row, 'sequence'),
            'subject_id' => $this->nullableStr($row, 'subject_id'),
            'actor_id' => $this->nullableStr($row, 'actor_id'),
            'event_type' => $this->str($row, 'event_type'),
            'resource_type' => $this->str($row, 'resource_type'),
            'resource_id' => $this->nullableStr($row, 'resource_id'),
            'occurred_at' => $this->str($row, 'occurred_at'),
            'payload_hash' => $this->str($row, 'payload_hash'),
            'previous_hash' => $this->str($row, 'previous_hash'),
            'event_hash' => $this->str($row, 'event_hash'),
            'signature_key_version' => (int) $this->num($row, 'signature_key_version'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function nullableStr(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : $this->str($row, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function num(array $row, string $key): int|float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? $value + 0 : 0;
    }
}
