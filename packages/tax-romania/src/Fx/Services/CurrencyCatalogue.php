<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Settings\Enums\SettingType;

/**
 * Which currencies this installation works in, and which it could.
 *
 * Two different lists, and conflating them is the mistake this class exists to prevent. **Available**
 * is what BNR has published a rate for, which is thirty-nine codes and not a choice anybody made:
 * the importer stores every `<Rate>` in the feed, because a rate nobody asked for costs nothing to
 * keep and a year nobody imported cannot be reconstructed later. **Enabled** is the handful a person
 * actually holds money in — what the forms offer.
 *
 * Enabled comes from a setting, so it is editable on the settings screen; available comes from the
 * rates themselves, so that screen offers what this application can genuinely convert rather than a
 * list somebody once typed into a frontend constant.
 */
final readonly class CurrencyCatalogue
{
    /**
     * Cached because the settings screen reads the available list and the answer changes once a day
     * at most — a new currency in the feed is news, not traffic.
     */
    public const string CACHE_KEY = 'fx.currencies.available';

    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * The codes the forms offer, base currency first.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $stored = $this->config->get('sources.fx.currencies');

        return SettingType::decodeCurrencyList(is_string($stored) ? $stored : null);
    }

    /**
     * Every code there is a stored rate for, plus the base currency, alphabetically.
     *
     * The base currency has no rate against itself and is in the list anyway: it is the one thing
     * every amount in this application can already be expressed in.
     *
     * Wrapped, because this is read while drawing a screen and a missing table must not be what
     * takes that screen down — on an installation that has never imported a rate the honest answer
     * is "what is enabled", not an error.
     *
     * @return list<string>
     */
    public function available(): array
    {
        try {
            /** @var list<string> $codes */
            $codes = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
                $rows = DB::table('ext_fx_rates')
                    ->distinct()
                    ->orderBy('currency_code')
                    ->pluck('currency_code')
                    ->all();

                return array_values(array_filter($rows, 'is_string'));
            });
        } catch (QueryException) {
            return $this->enabled();
        }

        $all = array_values(array_unique([SettingType::BASE_CURRENCY, ...$codes]));
        sort($all);

        return $all;
    }
}
