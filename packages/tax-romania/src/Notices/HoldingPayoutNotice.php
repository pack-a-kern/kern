<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use IntlDateFormatter;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\DataTransferObjects\PayoutTerms;
use Kern\Investments\Enums\PayoutKind;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Notice;
use Kern\Platform\Tenancy\Contracts\TenantGate;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * A holding's coupon or repayment is near, or due today — the deposits' maturity reminder, for bonds,
 * told to the taxpayer's owners at the same distances and hour.
 *
 * Stored with ids, the kind and the payment date only — public market facts, nothing of the person's.
 * The instrument's name is read each time it is shown; an account since deleted is not shown.
 */
final readonly class HoldingPayoutNotice extends TaxpayerNotice
{
    public function __construct(TenantGate $gate, private MarketData $market)
    {
        parent::__construct($gate);
    }

    public static function key(): string
    {
        return 'holding-payout';
    }

    public static function about(string $subjectId, string $accountId, PayoutTerms $payout, int $daysBefore): Notice
    {
        return new Notice(self::class, [
            'subject_id' => $subjectId,
            'account_id' => $accountId,
            'instrument_id' => $payout->instrumentId,
            'kind' => $payout->kind->value,
            'payment_date' => $payout->paymentDate->format('Y-m-d'),
            'days_before' => $daysBefore,
        ]);
    }

    public function label(string $locale): string
    {
        return __('tax-romania::notifications.types.holding-payout.label', [], $locale);
    }

    public function hint(string $locale): string
    {
        return __('tax-romania::notifications.types.holding-payout.hint', $this->reminderSchedule(), $locale);
    }

    public function render(array $notices, Model $notifiable, string $locale): array
    {
        $visible = $this->visibleTo($notifiable, $notices);
        $data = array_column($visible, 'data');
        $accountIds = array_values(array_filter(array_column($data, 'account_id'), is_string(...)));
        /** @var array<string, string> $accounts account id => its taxpayer */
        $accounts = $accountIds === [] ? [] : FinancialAccount::query()->withoutGlobalScope(TenantScope::class)
            ->whereKey($accountIds)
            ->pluck('tax_subject_id', 'id')
            ->all();
        $instruments = $this->market->instruments(array_values(array_filter(array_column($data, 'instrument_id'), is_string(...))));
        $messages = [];

        foreach ($visible as $id => ['subject' => $subjectId, 'data' => $notice]) {
            $instrument = $instruments[is_string($notice['instrument_id'] ?? null) ? $notice['instrument_id'] : ''] ?? null;
            $kind = PayoutKind::tryFrom(is_string($notice['kind'] ?? null) ? $notice['kind'] : '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', is_string($notice['payment_date'] ?? null) ? $notice['payment_date'] : '');
            $days = $notice['days_before'] ?? null;
            $account = is_string($notice['account_id'] ?? null) ? $notice['account_id'] : '';

            if ($instrument === null || $kind === null || $kind === PayoutKind::DIVIDEND || $date === false || ! is_int($days) || ($accounts[$account] ?? null) !== $subjectId) {
                continue;
            }

            $what = strtolower($kind->value);
            $formatted = (new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC'))->format($date);

            $messages[$id] = new Message(
                title: match ($days) {
                    0 => __("tax-romania::notifications.holdings.{$what}.title_today", [], $locale),
                    1 => __("tax-romania::notifications.holdings.{$what}.title_tomorrow", [], $locale),
                    default => __("tax-romania::notifications.holdings.{$what}.title_soon", ['days' => (string) $days], $locale),
                },
                body: __('tax-romania::notifications.holdings.body', [
                    'instrument' => $instrument->name,
                    'date' => $formatted === false ? $date->format('Y-m-d') : $formatted,
                ], $locale),
                url: $this->link('/wallet/accounts', $subjectId),
                tag: "holding-{$account}-{$instrument->id}-{$date->format('Y-m-d')}",
            );
        }

        return $messages;
    }
}
