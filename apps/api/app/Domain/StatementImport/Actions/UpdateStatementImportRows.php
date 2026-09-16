<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\StatementImport\Enums\RowProposalSource;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\Wallet\Enums\MoneyDirection;
use App\Domain\Wallet\Services\TagDirectory;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * What a person changes while going through a statement: which rows to keep, and what they were.
 *
 * ## Why it takes a set of rows
 *
 * Because that is how the work is actually done. Nobody answers three hundred rows one at a time —
 * they tick forty supermarket payments, set them all to Expense with a "groceries" tag, and move on.
 * One request per row would be forty requests and forty chances to leave the set half-applied.
 *
 * ## The same rules as anywhere else
 *
 * A category that cannot have the row's direction is dropped rather than stored: the review screen
 * filters the list it offers, so this only happens to a screen left open across a change, and a
 * staged row carrying an impossible answer would fail at the very end, after the person had stopped
 * looking.
 */
final readonly class UpdateStatementImportRows
{
    public function __construct(private TaxCategoryCatalogue $categories)
    {
    }

    /**
     * @param  list<string>  $rowIds
     * @param  array{included?: bool, category_code?: ?string, country_code?: ?string, tags?: list<string>}  $changes
     * @return int how many rows were changed
     */
    public function __invoke(StatementImport $import, array $rowIds, array $changes): int
    {
        if (! $import->status->isOpen()) {
            throw ImportNotOpenException::forImport($import->id);
        }

        if ($rowIds === []) {
            return 0;
        }

        // Normalised once for the whole set rather than per row: the same three tags typed once are
        // the same three tags for every row they are applied to.
        $tagNames = array_key_exists('tags', $changes)
            ? array_values(array_unique(array_filter(array_map(
                TagDirectory::normalise(...),
                $changes['tags'],
            ))))
            : null;

        $changed = 0;

        DB::transaction(function () use ($import, $rowIds, $changes, $tagNames, &$changed): void {
            /** @var list<StatementImportRow> $rows */
            $rows = StatementImportRow::query()
                ->where('import_id', $import->id)
                ->whereKey($rowIds)
                ->get()
                ->all();

            foreach ($rows as $row) {
                if (array_key_exists('included', $changes)) {
                    // A row with no date or no amount cannot become a transaction, so it cannot be
                    // ticked — the screen shows why, rather than accepting a choice it would drop.
                    $row->included = $changes['included']
                        && $row->booked_at !== null
                        && $row->amount_minor !== null;
                }

                if (array_key_exists('category_code', $changes)) {
                    $category = $changes['category_code'];

                    $row->category_code = $category !== null
                        && $row->amount_minor !== null
                        && $this->categories->fitsDirection($category, $row->amount_minor)
                            ? $category
                            : null;
                }

                if (array_key_exists('country_code', $changes)) {
                    $row->country_code = $changes['country_code'];
                }

                if ($tagNames !== null) {
                    $row->tags_ciphertext = $tagNames === [] ? null : json_encode($tagNames, JSON_THROW_ON_ERROR);
                }

                // Whatever was proposed, this is now the person's answer — which is what makes it
                // worth remembering when the import is committed.
                $row->proposal_source = RowProposalSource::PERSON;
                $row->save();
                $changed++;
            }

            $import->excluded_count = StatementImportRow::query()
                ->where('import_id', $import->id)
                ->where('included', false)
                ->count();
            $import->save();
        });

        return $changed;
    }

    /** The direction of a staged row's money, for a screen filtering the categories it offers. */
    public static function directionOf(StatementImportRow $row): ?MoneyDirection
    {
        return $row->amount_minor === null ? null : MoneyDirection::ofAmount($row->amount_minor);
    }
}
