<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\BlindIndex\Contracts\BlindIndexReindexer;
use Kern\Platform\Crypto\BlindIndex\DataTransferObjects\ReindexOutcome;

/**
 * The ordinary case: one model, one encrypted column, one index column derived from it.
 *
 * Almost every blind index in this codebase is that shape, so it is one class configured per column
 * rather than one class per column — a table contributes a factory call, not a file. The two things
 * that do differ are supplied as closures: which rows to walk, and how the value is normalised
 * before it is hashed (an email is lowercased, a CNP is stripped to digits, a fiscal identifier is
 * prefixed with its kind, most things are `Normalizer::text`).
 *
 * The normaliser takes the model rather than a string because some columns need a second attribute
 * to normalise at all — `tax_subjects.fiscal_identifier_kind` decides whether the value is hashed as
 * a CNP or as a CUI, and hashing it under the wrong one produces an index nothing will ever match.
 * Returning null means "this row cannot be recomputed", which is counted and reported, never guessed
 * at.
 *
 * Writes with `saveQuietly()`: re-indexing is not a change to the record. An event, an observer or a
 * touched `updated_at` would make a key rotation look like every row having been edited.
 */
final readonly class EloquentColumnReindexer implements BlindIndexReindexer
{
    /**
     * @param  Closure(): Builder<covariant Model>  $query  the rows to walk, tenant scope already lifted.
     * @param  Closure(Model): ?string  $normalize  the value to hash, or null when it cannot be recovered.
     */
    public function __construct(
        private string $label,
        private Closure $query,
        private string $indexColumn,
        private Closure $normalize,
        private BlindIndexHasher $hasher,
    ) {
    }

    public function label(): string
    {
        return $this->label;
    }

    public function reindex(int $batch): ReindexOutcome
    {
        $examined = 0;
        $rewritten = 0;
        $unrecomputable = 0;

        ($this->query)()
            ->orderBy('id')
            ->chunkById($batch, function (mixed $rows) use (&$examined, &$rewritten, &$unrecomputable): void {
                /** @var iterable<Model> $rows */
                foreach ($rows as $row) {
                    $examined++;
                    $value = ($this->normalize)($row);

                    if ($value === null || $value === '') {
                        $unrecomputable++;

                        continue;
                    }

                    $index = $this->hasher->compute($value);

                    if ($row->getAttribute($this->indexColumn) === $index) {
                        continue;
                    }

                    $row->setAttribute($this->indexColumn, $index);
                    $row->saveQuietly();
                    $rewritten++;
                }
            });

        return new ReindexOutcome($this->label, $examined, $rewritten, $unrecomputable);
    }
}
