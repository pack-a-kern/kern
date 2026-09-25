<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\Banks\Models\Bank;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\FileSecurity\FilenameSanitizer;
use App\Domain\Documents\Models\Document;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Str;

/**
 * Names a statement's file after it has been read, from what the reading found out about it.
 *
 * ## Why this is not proposed
 *
 * The review screen proposes a name for a document a model read, and a person accepts it, because a
 * model can be wrong about what it read. Nothing here is a reading: the bank was confirmed on the
 * mapping screen and the period is the first and last day the rows themselves carry. There is no
 * guess to approve, so the file is simply named.
 *
 * ## The id is only there when it has to be
 *
 * `extras_banca-transilvania_2026-04-10_2026-09-23.csv` says everything a person needs. The same
 * account can be handed the same period twice — a statement re-exported after a correction — and two
 * files with one name in a list is a question nobody can answer, so *that* file, and only that one,
 * ends with a piece of the import's id.
 *
 * A name a person has already typed is left alone: theirs outranks this.
 */
final readonly class NameStatementFile
{
    public function __construct(private FilenameSanitizer $sanitizer)
    {
    }

    public function __invoke(StatementImport $import): void
    {
        $document = $import->document_id === null
            ? null
            : Document::withoutGlobalScope(TenantScope::class)->find($import->document_id);

        if (! $document instanceof Document || $document->display_name_ciphertext !== null) {
            return;
        }

        $rows = StatementImportRow::withoutGlobalScope(TenantScope::class)
            ->where('import_id', $import->id)
            ->whereNotNull('booked_at');

        $parts = array_filter(
            ['extras', $this->bank($import), $this->day($rows->clone()->min('booked_at')), $this->day($rows->clone()->max('booked_at'))],
            static fn (?string $part): bool => $part !== null && $part !== '',
        );

        $name = implode('_', $parts);
        $extension = $this->extension($import);

        if ($this->taken($document, $name . $extension)) {
            $name .= '_' . Str::before($import->id, '-');
        }

        $document->display_name_ciphertext = $this->sanitizer->sanitize($name . $extension);

        $document->save();
    }

    /**
     * Whether another statement of this taxpayer already goes by this name.
     *
     * Compared in PHP rather than in a `where`: a document's name is encrypted under the subject's
     * own key, so there is nothing to match on in SQL. Only statements are read — one per import,
     * which is a handful — and never this document itself.
     */
    private function taken(Document $document, string $name): bool
    {
        return Document::withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $document->tax_subject_id)
            ->where('source', DocumentSource::IMPORT)
            ->whereKeyNot($document->id)
            ->whereNotNull('display_name_ciphertext')
            ->get(['id', 'tax_subject_id', 'display_name_ciphertext'])
            ->contains(static fn (Document $other): bool => $other->display_name_ciphertext === $name);
    }

    /** A day of the period, where the rows carry one: `2026-01-03`. */
    private function day(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value, 0, 10) : null;
    }

    /** The bank's name as a word — "Banca Transilvania" is `banca-transilvania`. */
    private function bank(StatementImport $import): ?string
    {
        $name = $import->bank_id === null ? null : Bank::query()->whereKey($import->bank_id)->value('name');

        return is_string($name) ? Str::slug($name) : null;
    }

    /** From what the file turned out to be, not from what it was called: `.xlsx` named `.csv` is worse than no name. */
    private function extension(StatementImport $import): string
    {
        return $import->format === null ? '' : '.' . mb_strtolower($import->format->value);
    }
}
