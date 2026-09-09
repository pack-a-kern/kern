<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\DataTransferObjects\SourceRegistryEntry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The official-source allowlist, read from `config/sources.php` — which is where it lives.
 *
 * This class exists in its current shape because the first version got the authority backwards. It
 * read `official_sources` and offered a toggle over that table's `enabled` column, which is wrong
 * twice over:
 *
 *  1. **`official_sources` is a mirror, not the source of truth.**
 *     `App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository::resolve()` upserts
 *     `trust_tier`/`base_url`/`enabled` from the current config on *every* call, so a value written
 *     to that table survives only until the next fetch resolves the source.
 *  2. **Nothing reads that column.** `App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher`
 *     checks the config-derived `SourceDefinition`, never the row.
 *
 * A switch on that column would therefore have appeared to disable a source while every import kept
 * running — a control that reports a state it does not hold, which is worse than no control. The
 * allowlist is presented read-only here for the same reason `SettingKey::isReadOnly()` refuses the
 * KMS driver: a value the server cannot honour must not be offered as editable.
 *
 * Reading the table is still worth one query, for `mirrored`: it distinguishes "declared but never
 * fetched" from "in use", which is exactly the difference an operator sees after a restore.
 *
 * Config is read directly rather than through `App\Domain\TaxKnowledge\Config\
 * OfficialSourceRegistry`: a config file is data, not another module's code, and this module has no
 * business importing that module's fetching logic to render a table.
 */
final readonly class ReadSourceAllowlist
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * @return list<SourceRegistryEntry>
     */
    public function __invoke(): array
    {
        $entries = $this->config->get('sources.entries');

        if (! is_array($entries)) {
            return [];
        }

        $mirrored = $this->mirroredCodes();
        $allowlist = [];

        foreach ($entries as $code => $entry) {
            if (! is_string($code) || ! is_array($entry)) {
                continue;
            }

            $trustTier = $entry['trust_tier'] ?? null;
            $baseUrl = $entry['base_url'] ?? null;

            $allowlist[] = new SourceRegistryEntry(
                code: $code,
                trustTier: is_string($trustTier) ? $trustTier : '',
                baseUrl: is_string($baseUrl) ? $baseUrl : '',
                enabled: ($entry['enabled'] ?? null) === true,
                mirrored: in_array($code, $mirrored, true),
            );
        }

        return $allowlist;
    }

    /**
     * Codes with a row in `official_sources`. Read through the plain query builder, never through
     * `Domain/TaxKnowledge`'s Eloquent model — the same crossing every other probe in this module
     * uses (`docs/AGENTS.md` §6). Degrades to "none mirrored" rather than failing the overview.
     *
     * @return list<string>
     */
    private function mirroredCodes(): array
    {
        try {
            /** @var list<string> $codes */
            $codes = DB::table('official_sources')->pluck('code')->all();

            return $codes;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
