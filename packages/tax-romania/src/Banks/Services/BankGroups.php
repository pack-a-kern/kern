<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use JsonException;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\TaxRomania\Banks\Exceptions\BankSourceNotConfiguredException;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Banks\Parsing\LegalForms;
use Throwable;

/**
 * Which group each bank belongs to — BCR to Erste Group — from GLEIF's ownership records.
 *
 * One request per bank with an LEI, through the same allowlisted fetcher as every other download,
 * after the import has written the list. The answer is GLEIF's "ultimate parent": the top of the
 * group, as consolidated accounts report it. Not kept as evidence: it describes a bank on a screen,
 * and nothing is computed from it.
 *
 * - A parent: the bank gets its name, built like a bank's own, and its LEI.
 * - 404 — the entity reports no parent — or a parent that is the bank itself: no group.
 * - Any other failure for one bank: that bank keeps what it had, the rest carry on, and the count
 *   says how many could not be asked. The bank list is already written, and a group is not worth
 *   undoing it for.
 */
final readonly class BankGroups
{
    public const string GLEIF = 'GLEIF';

    public function __construct(
        private ConfigRepository $config,
        private AllowlistedHttpFetcher $fetcher,
        private BankNameBuilder $names,
    ) {
    }

    /**
     * @param  list<string>  $countries
     * @return array{grouped: int, failed: int}
     */
    public function refresh(array $countries, LegalForms $legalForms): array
    {
        $template = $this->config->get('sources.entries.' . self::GLEIF . '.endpoints.ultimate_parent_template');

        if (! is_string($template) || ! str_contains($template, '{lei}')) {
            throw BankSourceNotConfiguredException::endpoint(self::GLEIF, 'ultimate_parent_template');
        }

        $grouped = 0;
        $failed = 0;

        $banks = Bank::query()->whereIn('country_code', $countries)->where('active', true)->whereNotNull('lei')->get();

        foreach ($banks as $bank) {
            try {
                $group = $this->groupOf((string) $bank->lei, $template, $legalForms);
            } catch (Throwable $exception) {
                // The reason, not the answer: what GLEIF said about one bank is no secret, but a
                // log line per bank is noise. The class and the bank say enough to look.
                Log::warning('A bank group could not be read from GLEIF.', ['bank_id' => $bank->id, 'error' => $exception::class]);
                $failed++;

                continue;
            }

            $bank->fill(['group_name' => $group['name'] ?? null, 'group_lei' => $group['lei'] ?? null])->save();
            $grouped += $group === null ? 0 : 1;
        }

        return ['grouped' => $grouped, 'failed' => $failed];
    }

    /**
     * @return array{name: string, lei: string}|null
     *
     * @throws JsonException
     */
    private function groupOf(string $lei, string $template, LegalForms $legalForms): ?array
    {
        try {
            $body = $this->fetcher->fetch(self::GLEIF, str_replace('{lei}', rawurlencode($lei), $template))->body;
        } catch (UnexpectedStatusException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        $record = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $parentLei = data_get($record, 'data.id');
        $name = data_get($record, 'data.attributes.entity.legalName.name');
        $country = data_get($record, 'data.attributes.entity.legalAddress.country');

        if (! is_string($parentLei) || ! is_string($name) || ! is_string($country) || strtoupper($parentLei) === strtoupper($lei)) {
            return null;
        }

        return [
            'name' => $this->names->fromRegisteredName($name, strtoupper($country), $legalForms),
            'lei' => strtoupper($parentLei),
        ];
    }
}
