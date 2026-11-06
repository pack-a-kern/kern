<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Queries;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownFiscalParameterException;

/**
 * The next day a Declarația unică is due, as a person approved it from the law.
 *
 * A year's declaration is due in the year after it, on the day the law of that year sets
 * (`fiscal_parameters.filing_deadline`) — read as it stood on the year's last day, the wording the
 * declaration is then filed under. Last year's declaration first, while its day is still ahead;
 * this year's after it.
 *
 * Only an approved value counts. A candidate is a reading nobody has checked yet, and a deadline is
 * what a person plans around: an unapproved one is not shown rather than shown as a guess.
 */
final readonly class FilingDeadline
{
    public function __construct(private FiscalParameterProvider $parameters, private ConfigRepository $config)
    {
    }

    /** @return array{income_year: int, due_on: string, days_left: int}|null */
    public function next(): ?array
    {
        $today = CarbonImmutable::today($this->config->string('fiscal_parameters.timezone'));

        foreach ([$today->year - 1, $today->year] as $year) {
            $due = $this->dueFor($year);

            if ($due !== null && $due >= $today) {
                // Counted here, on Romania's day, so neither the server's clock nor a browser in
                // another timezone says one day more or less than the law does.
                return ['income_year' => $year, 'due_on' => $due->format('Y-m-d'), 'days_left' => (int) $today->diffInDays($due)];
            }
        }

        return null;
    }

    /** The day one year's declaration is due, where an approved value says it. */
    private function dueFor(int $year): ?CarbonImmutable
    {
        try {
            $value = $this->parameters->versionInForceOn($this->config->string('fiscal_parameters.filing_deadline'), new DateTimeImmutable(sprintf('%04d-12-31', $year)));
        } catch (UnknownFiscalParameterException|NoFiscalParameterVersionInForceException|AmbiguousFiscalParameterVersionException) {
            return null;
        }

        if ($value->status !== RuleApprovalStatus::APPROVED) {
            return null;
        }

        $json = $value->asJson();
        $day = $json['day'] ?? null;
        $month = $json['month'] ?? null;

        return is_int($day) && is_int($month) && checkdate($month, $day, $year + 1)
            ? CarbonImmutable::create($year + 1, $month, $day, 0, 0, 0, $this->config->string('fiscal_parameters.timezone'))
            : null;
    }
}
