<?php

declare(strict_types=1);

namespace Kern\TaxRomania;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Contracts\BlindIndexReindexer;
use Kern\Platform\Crypto\BlindIndex\EloquentColumnReindexer;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvMessage;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\StatementImport\Services\StatementFingerprintReindexer;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\Tag;

/**
 * Every blind-indexed column this package owns, and how each one is recomputed.
 *
 * At the package root rather than inside a module because the list is a fact about the package: the
 * columns belong to four different modules, and a class inside any one of them would have to reach
 * into the other three. Nothing here is domain logic — it is a table of column names and normalisers,
 * read once at boot by `TaxRomaniaServiceProvider` and never by anything at request time.
 *
 * The normaliser beside each column is not decoration. A blind index is only findable if the value is
 * normalised the same way on the way in and on the way out, so each entry repeats the exact call the
 * writing code makes, and a test compares the two. Getting one wrong produces an index that no lookup
 * will ever match — silently, because a hash is a hash.
 *
 * Every model here is queried with its tenant scope lifted: this is maintenance across every
 * taxpayer, the same deliberate, visible exception `IndexCounterpartiesCommand` already makes.
 */
final class BlindIndexColumns
{
    private function __construct()
    {
    }

    /**
     * Container key => how to build that column's reindexer.
     *
     * @return array<string, Closure(Application): BlindIndexReindexer>
     */
    public static function definitions(): array
    {
        return [
            /*
             * A taxpayer's CNP or CUI. The kind is part of what is hashed — `FiscalIdentifierKind`
             * binds it into the string precisely so `CNP:123…` and `CUI:123…` can never collide — so a
             * row without one cannot be recomputed at all, rather than being hashed as a guess.
             */
            'kern.reindexer.tax-subjects-fiscal-identifier' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'tax_subjects.fiscal_identifier_blind_index',
                query: static fn (): Builder => TaxSubject::query()
                    ->whereNotNull('fiscal_identifier_ciphertext')
                    ->whereNotNull('fiscal_identifier_kind'),
                indexColumn: 'fiscal_identifier_blind_index',
                normalize: static function (Model $row): ?string {
                    $value = $row->getAttribute('fiscal_identifier_ciphertext');
                    $kind = $row->getAttribute('fiscal_identifier_kind');

                    return is_string($value) && $value !== '' && $kind instanceof FiscalIdentifierKind
                        ? $kind->keyedValue($value)
                        : null;
                },
                hasher: $app->make(BlindIndexHasher::class),
            ),

            // `name_ciphertext` already holds the normalised name — `TagDirectory` writes it that way
            // — so the one normalisation left is the one the hash itself is computed over.
            'kern.reindexer.tags-name' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'tags.name_blind_index',
                query: static fn (): Builder => Tag::query()->withoutGlobalScope(TenantScope::class),
                indexColumn: 'name_blind_index',
                normalize: self::text('name_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            'kern.reindexer.spv-connections-username' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'spv_connections.username_blind_index',
                query: static fn (): Builder => SpvConnection::query()->withoutGlobalScope(TenantScope::class),
                indexColumn: 'username_blind_index',
                normalize: self::text('username_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            'kern.reindexer.spv-messages-external-id' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'spv_messages.external_id_blind_index',
                query: static fn (): Builder => SpvMessage::query()->withoutGlobalScope(TenantScope::class),
                indexColumn: 'external_id_blind_index',
                normalize: self::text('external_id_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            'kern.reindexer.transactions-counterparty' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'financial_transactions.counterparty_blind_index',
                query: static fn (): Builder => FinancialTransaction::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->whereNotNull('counterparty_ciphertext'),
                indexColumn: 'counterparty_blind_index',
                normalize: self::text('counterparty_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            /*
             * `external_id_blind_index` holds two different things, and only one of them belongs here.
             * A manually recorded transaction puts the hash of its own external reference in it, and
             * keeps that reference in `external_id_ciphertext`. An imported one puts the statement
             * line's fingerprint there instead and stores no ciphertext — recomputed by
             * `StatementFingerprintReindexer` from the import rows, which is where its inputs live.
             * The `whereNotNull` is what keeps the two apart.
             */
            'kern.reindexer.transactions-external-id' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'financial_transactions.external_id_blind_index',
                query: static fn (): Builder => FinancialTransaction::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->whereNotNull('external_id_ciphertext'),
                indexColumn: 'external_id_blind_index',
                normalize: self::text('external_id_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            // A broker's own reference for a position, written by `AddHolding` exactly this way.
            'kern.reindexer.investment-lots-external-ref' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'investment_lots.external_ref_blind_index',
                query: static fn (): Builder => InvestmentLot::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->whereNotNull('external_ref_ciphertext'),
                indexColumn: 'external_ref_blind_index',
                normalize: self::text('external_ref_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            'kern.reindexer.statement-rows-counterparty' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'statement_import_rows.counterparty_blind_index',
                query: static fn (): Builder => StatementImportRow::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->whereNotNull('counterparty_ciphertext'),
                indexColumn: 'counterparty_blind_index',
                normalize: self::text('counterparty_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            // Re-keyable only because the rule now carries the name it indexes — see that table's
            // `2026_09_27_100000_give_counterparties_the_name_they_index` migration.
            'kern.reindexer.counterparty-rules' => static fn (Application $app): BlindIndexReindexer => new EloquentColumnReindexer(
                label: 'counterparties.counterparty_blind_index',
                query: static fn (): Builder => Counterparty::query()->withoutGlobalScope(TenantScope::class),
                indexColumn: 'counterparty_blind_index',
                normalize: self::text('counterparty_ciphertext'),
                hasher: $app->make(BlindIndexHasher::class),
            ),

            'kern.reindexer.statement-fingerprints' => static fn (Application $app): BlindIndexReindexer => $app->make(StatementFingerprintReindexer::class),
        ];
    }

    /**
     * The common case: hash `Normalizer::text()` of one encrypted column, trimmed.
     *
     * Trimmed because the writing side trims — `CounterpartyDirectory::indexFor()` does it explicitly, and
     * a value that is only whitespace has no index at all rather than the index of an empty string.
     *
     * @return Closure(Model): ?string
     */
    private static function text(string $column): Closure
    {
        return static function (Model $row) use ($column): ?string {
            $value = $row->getAttribute($column);

            if (! is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed === '' ? null : Normalizer::text($trimmed);
        };
    }
}
