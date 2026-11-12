<?php

declare(strict_types=1);

namespace Kern\Notifications\Actions;

use Illuminate\Support\Collection;
use Kern\Notifications\Models\StoredNotice;

/**
 * The bell's notices of one kind about things that no longer exist — an issue deleted with the order it was
 * read from — taken out, read or not, so none is left naming what is gone.
 *
 * Matched on the notice's own data, compared here rather than in SQL: `data` is JSON kept as text, which
 * the two databases read differently, and a kind's notices are few enough to be read through in pages.
 */
final readonly class WithdrawNotices
{
    private const int PAGE = 500;

    /** @param  list<string>  $values  the ids the notices name under `$field` */
    public function __invoke(string $type, string $field, array $values): void
    {
        $gone = array_flip($values);

        StoredNotice::query()->where('type', $type)->chunkById(self::PAGE, static function (Collection $notices) use ($field, $gone): void {
            $withdrawn = $notices
                ->filter(static fn (StoredNotice $notice): bool => is_string($notice->data[$field] ?? null) && isset($gone[$notice->data[$field]]))
                ->modelKeys();

            if ($withdrawn !== []) {
                StoredNotice::query()->whereKey($withdrawn)->delete();
            }
        });
    }
}
