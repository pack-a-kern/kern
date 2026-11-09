<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use Illuminate\Database\Eloquent\Model;
use IntlDateFormatter;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Notifications\Contracts\NoticeType;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Notice;
use NumberFormatter;

/**
 * A new government bond issue can be subscribed (ADR 0025 §8) — told to everyone whose switch for it is
 * on, when `kern/investments` first reads it from its order (`IssueOnOffer`).
 *
 * Not a `TaxpayerNotice`: it is about the market, the same for everyone, so there is no taxpayer to ask
 * about and nothing personal in it. Stored with the instrument's id only; its programme, code, rate and
 * maturity are read from the market each time it is shown, so the bell and the device say the same.
 */
final readonly class IssueOnOfferNotice implements NoticeType
{
    public function __construct(private MarketData $market)
    {
    }

    public static function key(): string
    {
        return 'issue-on-offer';
    }

    public static function about(string $instrumentId): Notice
    {
        return new Notice(self::class, ['instrument_id' => $instrumentId]);
    }

    public function label(string $locale): string
    {
        return __('tax-romania::notifications.types.issue-on-offer.label', [], $locale);
    }

    public function hint(string $locale): string
    {
        return __('tax-romania::notifications.types.issue-on-offer.hint', [], $locale);
    }

    public function render(array $notices, Model $notifiable, string $locale): array
    {
        $instruments = $this->market->instruments(array_values(array_filter(array_column($notices, 'instrument_id'), is_string(...))));
        $messages = [];

        foreach ($notices as $id => $data) {
            $instrument = $instruments[is_string($data['instrument_id'] ?? null) ? $data['instrument_id'] : ''] ?? null;

            // Once its subscription is over, the news is too.
            if ($instrument === null || $instrument->status !== InstrumentStatus::ON_OFFER || $instrument->maturityDate === null) {
                continue;
            }

            $maturity = (new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC'))->format($instrument->maturityDate);

            $messages[$id] = new Message(
                title: __('tax-romania::notifications.issues.title', ['name' => $instrument->name], $locale),
                body: __('tax-romania::notifications.issues.body', [
                    // Formatted for reading, not arithmetic: the decimal text is the rate.
                    'rate' => (string) (new NumberFormatter($locale, NumberFormatter::DECIMAL))->format((float) $instrument->ratePercent),
                    'currency' => $instrument->currency,
                    'date' => $maturity === false ? $instrument->maturityDate->format('Y-m-d') : $maturity,
                ], $locale),
                // The issue on the market: what it pays and until when it can be subscribed.
                url: "/investments/{$instrument->id}",
                tag: "issue-{$instrument->id}",
            );
        }

        return $messages;
    }
}
