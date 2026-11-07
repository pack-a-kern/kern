<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\TaxRomania\Banks\DataTransferObjects\BankBackfillSummary;
use Kern\TaxRomania\Banks\DataTransferObjects\BankReferenceFiles;
use Kern\TaxRomania\Banks\DataTransferObjects\EcbInstitution;
use Kern\TaxRomania\Banks\Exceptions\BankSourceNotConfiguredException;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Banks\Services\BankSources;

/**
 * Adds the banks that closed or merged before the first import, from the ECB's archived lists.
 *
 * The daily import only ever sees today's list, so a bank marks itself inactive only if it was seen
 * once and then vanished. Alpha Bank Romania merged into UniCredit in August 2025, a year before
 * the first import here, and was simply never in the table — while statements from it are exactly
 * what a person imports.
 *
 * ## What it changes, and what it never does
 *
 * - A bank not in the table is added, **inactive**, dated by the first and last archived lists it
 *   appears in. It is described by the latest of those lists.
 * - A bank already in the table keeps everything it has; only its `first_seen_on` moves back, to the
 *   earliest list that names it.
 * - The current banks are never touched otherwise: which of them are active is today's list's
 *   answer, and an old list is no authority on it.
 *
 * Idempotent: a second run finds the same banks and dates, and adds nothing.
 *
 * ## Up to today, every time
 *
 * It reads every month from `banks.backfill.from` to today. It used to stop at the earliest
 * `first_seen_on` in the table, taken for the day the daily import began — a date this very
 * backfill moves back as it reads. A run stopped part-way (the queue's sixty seconds, on the first
 * try) had moved some bank to January 2017, and every run after it stopped there, before reading a
 * single list: the banks that closed later, Alpha Bank among them, were never added. Reading to
 * today costs the months the daily import also saw, which it changes nothing in, and makes a run
 * that was stopped one that simply goes on the next time.
 *
 * ## A merged bank's BIC
 *
 * BICs come from GLEIF's current file, which keeps a merged bank's LEI but points it at its
 * successor's code: Alpha Bank Romania's LEI now maps to UniCredit's `BACXROBUXXX`. Copying that
 * would give the old bank the new one's code, so a BIC a current bank holds is not given to an old
 * one — it is left without, which is the truth about what the public files still say.
 */
final readonly class BackfillBanks
{
    public function __construct(
        private BankSources $sources,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @param  string|null  $from  `YYYY-MM`, the first month read; null for `banks.backfill.from`
     * @param  (callable(string, int|null): void)|null  $onMonth  told each month's list date and bank count, or null when none was found
     */
    public function __invoke(?string $from = null, ?callable $onMonth = null): BankBackfillSummary
    {
        // The daily import first: the reference files and today's banks are what an old list is read
        // against, and a backfill with no current banks would add every one of them as closed.
        if (! Bank::query()->whereIn('country_code', $this->sources->filter('countries'))->exists()) {
            throw BankSourceNotConfiguredException::nothingImported();
        }

        $until = CarbonImmutable::now('UTC')->startOfDay();
        $month = CarbonImmutable::createFromFormat('!Y-m', $from ?? $this->config->string('banks.backfill.from'), 'UTC');

        if ($month === null) {
            throw BankSourceNotConfiguredException::filter('backfill.from');
        }

        $files = $this->sources->referenceFiles();
        $snapshots = 0;
        $created = 0;
        $datedBack = 0;
        $withoutBic = 0;
        $missing = [];

        for (; $month->lessThan($until); $month = $month->addMonth()) {
            $found = $this->firstListOf($month, $until);

            if ($found === null) {
                $missing[] = $month->format('Y-m');
                $onMonth !== null && $onMonth($month->format('Y-m'), null);

                continue;
            }

            [$date, $institutions] = $found;
            $snapshots++;

            [$added, $moved, $noBic] = DB::transaction(fn (): array => $this->record($institutions, $date, $files));
            $created += $added;
            $datedBack += $moved;
            $withoutBic += $noBic;

            $onMonth !== null && $onMonth($date, count($institutions));
        }

        return new BankBackfillSummary(
            snapshots: $snapshots,
            created: $created,
            datedBack: $datedBack,
            withoutBic: $withoutBic,
            missingMonths: $missing,
            until: $until->format('Y-m-d'),
        );
    }

    /**
     * The month's first archived list, and its banks — or null when none of its first days has one.
     *
     * @return array{0: string, 1: list<EcbInstitution>}|null
     */
    private function firstListOf(CarbonImmutable $month, CarbonImmutable $until): ?array
    {
        $template = $this->config->string('sources.entries.' . BankSources::ECB . '.endpoints.institution_list_archive_template');

        for ($day = 0; $day < $this->config->integer('banks.backfill.days_to_try'); $day++) {
            $date = $month->addDays($day);

            if ($date->greaterThanOrEqualTo($until)) {
                return null;
            }

            try {
                return [$date->format('Y-m-d'), $this->sources->institutions(str_replace('{date}', $date->format('ymd'), $template))];
            } catch (UnexpectedStatusException $exception) {
                // A weekend or a holiday: the archive has no list for the day. Anything else is a
                // real failure and stops the run with the table as the last month left it.
                if (! $exception->isNotFound()) {
                    throw $exception;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<EcbInstitution>  $institutions
     * @return array{0: int, 1: int, 2: int} banks added, banks dated back, added banks without a BIC
     */
    private function record(array $institutions, string $date, BankReferenceFiles $files): array
    {
        $added = 0;
        $moved = 0;
        $noBic = 0;

        foreach ($institutions as $institution) {
            $bank = Bank::query()->where('ecb_code', $institution->ecbCode)->first();

            if ($bank === null) {
                $attributes = $this->sources->attributes($institution, $files);
                $attributes['bic'] = $this->heldByAnother($attributes['bic']) ? null : $attributes['bic'];
                $noBic += $attributes['bic'] === null ? 1 : 0;

                Bank::query()->create([
                    'ecb_code' => $institution->ecbCode,
                    ...$attributes,
                    'active' => false,
                    'first_seen_on' => $date,
                    'last_seen_on' => $date,
                ]);
                $added++;

                continue;
            }

            if ($bank->first_seen_on->format('Y-m-d') > $date) {
                $bank->fill(['first_seen_on' => $date]);
                $moved++;
            }

            // A closed bank is described by the latest list that still had it; a current one by
            // today's, which the daily import keeps.
            if (! $bank->active && $bank->last_seen_on->format('Y-m-d') < $date) {
                $attributes = $this->sources->attributes($institution, $files);
                $attributes['bic'] = $this->heldByAnother($attributes['bic'], $bank->id) ? null : $attributes['bic'];
                $bank->fill([...$attributes, 'last_seen_on' => $date]);
            }

            $bank->save();
        }

        return [$added, $moved, $noBic];
    }

    /** Whether a current bank already holds this BIC — an old bank's LEI pointing at its successor's code. */
    private function heldByAnother(?string $bic, ?string $bankId = null): bool
    {
        return $bic !== null && Bank::query()
            ->where('active', true)
            ->where('bic', $bic)
            ->when($bankId !== null, static fn ($query) => $query->whereKeyNot($bankId))
            ->exists();
    }
}
