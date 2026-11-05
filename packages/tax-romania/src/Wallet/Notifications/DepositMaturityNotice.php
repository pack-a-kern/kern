<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use IntlDateFormatter;
use Kern\Platform\Notifications\Channels\WebPushChannel;
use Kern\Platform\Notifications\Contracts\SendsPush;
use Kern\Platform\Notifications\DataTransferObjects\PushMessage;
use Kern\TaxRomania\Wallet\Models\DepositTerms;
use NumberFormatter;

/**
 * A term deposit is about to mature — or matures today — told to the taxpayer's owners.
 *
 * Two forms, for two places. The bell keeps ids, the distance and the date only: its table is not
 * encrypted and is no place for an amount. The device notification says exactly what is happening — the
 * bank, the principal and the date, in the device's language — built when it is sent, kept nowhere, and
 * encrypted to that one device on the way (`PushMessage`).
 *
 * The bank's name is handed in rather than looked up: banks are another module's
 * (`Kern\TaxRomania\Banks`), and the command that sends this is where the two meet.
 */
final class DepositMaturityNotice extends Notification implements SendsPush
{
    /** The kind declared for this notice (`TaxRomaniaServiceProvider::declareNotificationTypes()`). */
    public const string PUSH_TYPE = 'deposit-maturity';

    public function __construct(
        private readonly DepositTerms $terms,
        private readonly int $daysBefore,
        private readonly ?string $bankName,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', WebPushChannel::class];
    }

    public function databaseType(object $notifiable): string
    {
        return self::PUSH_TYPE;
    }

    public function broadcastType(): string
    {
        return self::PUSH_TYPE;
    }

    /** @return array{subject_id: string, account_id: string, days_before: int, maturity_at: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'subject_id' => $this->terms->tax_subject_id,
            'account_id' => $this->terms->account_id,
            'days_before' => $this->daysBefore,
            'maturity_at' => $this->terms->maturity_at->toDateString(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function pushType(): string
    {
        return self::PUSH_TYPE;
    }

    public function toPush(object $notifiable, string $locale): PushMessage
    {
        $title = match ($this->daysBefore) {
            0 => __('tax-romania::notifications.deposits.title_today', [], $locale),
            1 => __('tax-romania::notifications.deposits.title_tomorrow', [], $locale),
            default => __('tax-romania::notifications.deposits.title_soon', ['days' => (string) $this->daysBefore], $locale),
        };

        return new PushMessage(
            title: $title,
            body: __('tax-romania::notifications.deposits.body', [
                'bank' => $this->bankName ?? __('tax-romania::notifications.deposits.no_bank', [], $locale),
                'amount' => $this->amount($locale),
                'date' => $this->date($locale),
            ], $locale),
            url: '/wallet/accounts',
            tag: 'deposit-' . $this->terms->account_id,
        );
    }

    private function amount(string $locale): string
    {
        $formatted = (new NumberFormatter($locale, NumberFormatter::CURRENCY))
            ->formatCurrency($this->terms->principal_minor / 100, $this->terms->currency_code);

        return $formatted === false ? sprintf('%.2f %s', $this->terms->principal_minor / 100, $this->terms->currency_code) : $formatted;
    }

    private function date(string $locale): string
    {
        $maturity = $this->terms->maturity_at;
        $formatted = (new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC'))->format($maturity->getTimestamp());

        return $formatted === false ? $maturity->toDateString() : $formatted;
    }
}
