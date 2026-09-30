<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\DataTransferObjects;

/**
 * How one statement file is built: where every field lives, and what is not a transaction at all.
 *
 * ## Why a field can be a column *or* a label
 *
 * Two kinds of statement exist. In one, a transaction is a line and every field is a column. In the
 * other — the one a Romanian bank writes — a transaction is a line followed by its own detail lines,
 * and the counterparty arrives as `Tranzactie la: DIGI ROMANIA SA` beneath it. A form that only knew
 * about columns could describe the first kind and not the second, so every field here is a
 * {@see StatementField}: a column index, or a label.
 *
 * ## Why counterparty and reference are answered per direction
 *
 * Because a statement names the two sides differently. In the file this was built against, money out
 * carries `Tranzactie la` and `Numar autorizare`; money in carries `Ordonator` and `Referinta`. One
 * field for both would be right for one direction and empty for the other — which is exactly what a
 * model produced before the form was split, labelling 84% of the transactions with a reference that
 * belongs to the other 13%.
 *
 * ## What it is not
 *
 * Not a template for the next file. It describes *this* statement, and is kept with the import as
 * the record of how those figures were read.
 */
final readonly class StatementForm
{
    public const string LAYOUT_ROW = 'row';

    public const string LAYOUT_BLOCK = 'block';

    public const string MODE_SIGNED = 'signed';

    public const string MODE_DEBIT_CREDIT = 'debit_credit';

    /** The date shapes the reader describes and {@see StatementValueParser} can read. */
    public const array DATE_FORMATS = [
        'DD.MM.YYYY',
        'DD/MM/YYYY',
        'DD-MM-YYYY',
        'YYYY-MM-DD',
        'YYYY/MM/DD',
        'DD.MM.YY',
        'MM/DD/YYYY',
        'D MONTH YYYY',
    ];

    /**
     * @param  list<string>  $nonTransactionLines  text that a heading, footer or account-detail line starts with
     * @param  list<string>  $identityLabels  labels whose values are identifiers, dropped on sight
     */
    public function __construct(
        public string $layout,
        public int $dateColumn,
        public string $dateFormat,
        public string $amountMode,
        public ?int $amountColumn,
        public ?int $debitColumn,
        public ?int $creditColumn,
        public string $decimalSeparator,
        public string $thousandsSeparator,
        public ?StatementField $currency,
        /**
         * Where the payment's text is, in the order it reads.
         *
         * A list rather than one place, read exactly as `counterparty` is: the places are tried in
         * order and the first with anything in it wins. A card payment writes the text in one column
         * and a transfer in another, and a statement that uses one column names one place, which is
         * most of them.
         *
         * @var list<StatementField>
         */
        public array $description,
        /**
         * The places a counterparty and a reference may be found, in the order they are tried.
         *
         * A statement is not written by one machine: a card payment names its payee in a column and
         * a transfer names it on a detail line, and a reference column is sometimes the payee's name
         * again. So these two take a chain rather than an answer — `statement_import
         * .multi_source_fields` says which, and everything else takes exactly one place.
         *
         * @var list<StatementField>
         */
        public array $counterpartyOut,
        /** @var list<StatementField> */
        public array $counterpartyIn,
        /** @var list<StatementField> */
        public array $referenceOut,
        /** @var list<StatementField> */
        public array $referenceIn,
        public array $nonTransactionLines = [],
        public array $identityLabels = [],
    ) {
    }

    public function isBlock(): bool
    {
        return $this->layout === self::LAYOUT_BLOCK;
    }

    /**
     * The counterparty's places for money moving this way — the sign decides which side is asked.
     *
     * @return list<StatementField>
     */
    public function counterpartyFor(int $amountMinor): array
    {
        return $amountMinor < 0 ? $this->counterpartyOut : $this->counterpartyIn;
    }

    /** @return list<StatementField> */
    public function referenceFor(int $amountMinor): array
    {
        return $amountMinor < 0 ? $this->referenceOut : $this->referenceIn;
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $amounts = is_array($raw['amounts'] ?? null) ? $raw['amounts'] : [];
        $date = is_array($raw['date'] ?? null) ? $raw['date'] : [];
        $counterparty = is_array($raw['counterparty'] ?? null) ? $raw['counterparty'] : [];
        $reference = is_array($raw['reference'] ?? null) ? $raw['reference'] : [];

        $strings = static fn (string $key): array => array_values(array_filter(
            is_array($raw[$key] ?? null) ? $raw[$key] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        return new self(
            layout: ($raw['layout'] ?? null) === self::LAYOUT_BLOCK ? self::LAYOUT_BLOCK : self::LAYOUT_ROW,
            dateColumn: is_numeric($date['value'] ?? null) ? (int) $date['value'] : 0,
            dateFormat: is_string($date['format'] ?? null) ? $date['format'] : 'DD.MM.YYYY',
            amountMode: ($amounts['mode'] ?? null) === self::MODE_DEBIT_CREDIT
                ? self::MODE_DEBIT_CREDIT
                : self::MODE_SIGNED,
            amountColumn: StatementField::indexIn($amounts['amount'] ?? null),
            debitColumn: StatementField::indexIn($amounts['debit'] ?? null),
            creditColumn: StatementField::indexIn($amounts['credit'] ?? null),
            decimalSeparator: self::separator($amounts['decimal_separator'] ?? null, ','),
            thousandsSeparator: self::separator($amounts['thousands_separator'] ?? null, ''),
            currency: StatementField::fromArray($raw['currency'] ?? null),
            description: StatementField::chain($raw['description'] ?? null),
            counterpartyOut: StatementField::chain($counterparty['debit'] ?? null),
            counterpartyIn: StatementField::chain($counterparty['credit'] ?? null),
            referenceOut: StatementField::chain($reference['debit'] ?? null),
            referenceIn: StatementField::chain($reference['credit'] ?? null),
            nonTransactionLines: $strings('non_transaction_lines'),
            identityLabels: $strings('identity_labels'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'layout' => $this->layout,
            'date' => ['type' => 'index', 'value' => $this->dateColumn, 'format' => $this->dateFormat],
            'currency' => $this->currency?->toArray(),
            'amounts' => [
                'mode' => $this->amountMode,
                'amount' => $this->amountColumn === null ? null : ['type' => 'index', 'value' => $this->amountColumn],
                'debit' => $this->debitColumn === null ? null : ['type' => 'index', 'value' => $this->debitColumn],
                'credit' => $this->creditColumn === null ? null : ['type' => 'index', 'value' => $this->creditColumn],
                'decimal_separator' => $this->decimalSeparator,
                'thousands_separator' => $this->thousandsSeparator,
            ],
            'description' => StatementField::chainToArray($this->description),
            'counterparty' => [
                'debit' => StatementField::chainToArray($this->counterpartyOut),
                'credit' => StatementField::chainToArray($this->counterpartyIn),
            ],
            'reference' => [
                'debit' => StatementField::chainToArray($this->referenceOut),
                'credit' => StatementField::chainToArray($this->referenceIn),
            ],
            'non_transaction_lines' => $this->nonTransactionLines,
            'identity_labels' => $this->identityLabels,
        ];
    }

    /** One character, or nothing: a separator is a single character or it is not a separator. */
    private static function separator(mixed $value, string $default): string
    {
        return is_string($value) && mb_strlen($value) === 1 ? $value : $default;
    }
}
