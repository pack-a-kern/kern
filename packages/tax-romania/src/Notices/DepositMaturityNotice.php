<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use Illuminate\Database\Eloquent\Model;
use IntlDateFormatter;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Notice;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Wallet\Models\DepositTerms;
use NumberFormatter;

/**
 * A term deposit is about to mature, or matures today — told to its taxpayer's owners.
 *
 * Stored with the deposit's id and the distance only. What it says — the bank, the principal and the
 * date, in the reader's language — is read from the deposit each time it is shown, so the bell and the
 * device say exactly the same and neither the table nor the socket ever holds an amount.
 *
 * Here rather than in `Wallet` because it reads `Banks` as well, which the Wallet module may not.
 */
final readonly class DepositMaturityNotice extends TaxpayerNotice
{
    public static function key(): string
    {
        return 'deposit-maturity';
    }

    public static function about(DepositTerms $terms, int $daysBefore): Notice
    {
        return new Notice(self::class, [
            'subject_id' => $terms->tax_subject_id,
            'account_id' => $terms->account_id,
            'days_before' => $daysBefore,
        ]);
    }

    public function label(string $locale): string
    {
        return __('tax-romania::notifications.types.deposit-maturity.label', [], $locale);
    }

    /** The distances and the hour actually configured, so the switch says when it will speak. */
    public function hint(string $locale): string
    {
        /** @var list<int> $distances */
        $distances = config('wallet.deposit.reminder_days', []);
        // Read from the list, not by dotted path: the task's key has a dot of its own.
        $cron = config()->array('operations.schedule.expressions', [])[OperationTask::WALLET_MATURITY_REMINDERS->value] ?? '';
        $cron = is_string($cron) ? $cron : '';

        return __('tax-romania::notifications.types.deposit-maturity.hint', [
            'days' => implode(', ', array_filter($distances, static fn (int $days): bool => $days > 0)),
            'time' => preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/', $cron, $m) === 1 ? sprintf('%02d:%02d', (int) $m[2], (int) $m[1]) : $cron,
        ], $locale);
    }

    public function render(array $notices, Model $notifiable, string $locale): array
    {
        $visible = $this->visibleTo($notifiable, $notices);
        $accountIds = array_filter(array_column(array_column($visible, 'data'), 'account_id'), is_string(...));

        $deposits = $accountIds === [] ? collect() : DepositTerms::query()->withoutGlobalScope(TenantScope::class)
            ->whereKey(array_values($accountIds))
            ->get()
            ->keyBy('account_id');
        $bankIds = $deposits->pluck('bank_id')->filter()->unique()->values()->all();
        $banks = $bankIds === [] ? [] : Bank::query()->whereKey($bankIds)->pluck('name', 'id')->all();

        $messages = [];

        foreach ($visible as $id => ['subject' => $subjectId, 'data' => $data]) {
            $terms = $deposits->get(is_string($data['account_id'] ?? null) ? $data['account_id'] : '');
            $daysBefore = $data['days_before'] ?? null;

            if (! $terms instanceof DepositTerms || $terms->tax_subject_id !== $subjectId || ! is_int($daysBefore)) {
                continue;
            }

            $bank = $terms->bank_id === null ? null : ($banks[$terms->bank_id] ?? null);

            $messages[$id] = new Message(
                title: match ($daysBefore) {
                    0 => __('tax-romania::notifications.deposits.title_today', [], $locale),
                    1 => __('tax-romania::notifications.deposits.title_tomorrow', [], $locale),
                    default => __('tax-romania::notifications.deposits.title_soon', ['days' => (string) $daysBefore], $locale),
                },
                body: __('tax-romania::notifications.deposits.body', [
                    'bank' => is_string($bank) ? $bank : __('tax-romania::notifications.deposits.no_bank', [], $locale),
                    'amount' => $this->amount($terms, $locale),
                    'date' => $this->date($terms, $locale),
                ], $locale),
                url: $this->link('/wallet/accounts', $subjectId),
                tag: "deposit-{$terms->account_id}",
            );
        }

        return $messages;
    }

    private function amount(DepositTerms $terms, string $locale): string
    {
        // Formatting for reading, not arithmetic: the integer minor units are the figure.
        $major = $terms->principal_minor / 100;
        $formatted = (new NumberFormatter($locale, NumberFormatter::CURRENCY))->formatCurrency($major, $terms->currency_code);

        return $formatted === false ? sprintf('%.2f %s', $major, $terms->currency_code) : $formatted;
    }

    private function date(DepositTerms $terms, string $locale): string
    {
        $maturity = $terms->maturity_at;
        $formatted = (new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC'))->format($maturity->getTimestamp());

        return $formatted === false ? $maturity->toDateString() : $formatted;
    }
}
