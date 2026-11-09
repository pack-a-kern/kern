<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use InvalidArgumentException;

/**
 * What a portal search asks for: words of an act's title — of one year, if given — or every act of a
 * number in a year; of one type, if given.
 *
 * The same whichever way the portal is asked and whether the search is read at once or a page at a
 * time, so what an act must be to be kept is said once, here ({@see keeps()}), and not by each caller.
 */
final readonly class PortalQuery
{
    private function __construct(
        public ?string $words,
        public ?int $number,
        public ?int $year,
        /** The type as filed, {@see PortalAct::key()}; null for any. */
        public ?string $type,
    ) {
    }

    public static function byTitle(string $words, ?int $year = null, ?string $type = null): self
    {
        $words = trim($words);

        if ($words === '') {
            throw new InvalidArgumentException('A title search needs words to look for.');
        }

        return new self($words, null, $year, self::typeKey($type));
    }

    public static function byNumber(int $number, int $year, ?string $type = null): self
    {
        if ($number < 1) {
            throw new InvalidArgumentException('An act number is a positive number.');
        }

        return new self(null, $number, $year, self::typeKey($type));
    }

    /**
     * A query kept by {@see toArray()}, read back.
     *
     * @param  array<array-key, mixed>  $kept
     */
    public static function fromArray(array $kept): self
    {
        $year = is_int($kept['year'] ?? null) ? $kept['year'] : null;
        $type = is_string($kept['type'] ?? null) ? $kept['type'] : null;

        if (is_int($kept['number'] ?? null) && $year !== null) {
            return self::byNumber($kept['number'], $year, $type);
        }

        if (is_string($kept['words'] ?? null)) {
            return self::byTitle($kept['words'], $year, $type);
        }

        throw new InvalidArgumentException('A kept portal query names neither words nor a number and year.');
    }

    public function isByNumber(): bool
    {
        return $this->number !== null;
    }

    /**
     * Whether an act the portal listed is one this query asked for.
     *
     * The portal's own narrowing is a hint, never the answer: asked for a number in a year it lists every
     * act so numbered, of any type and some of other years; asked for a year beside a title, the service
     * ignores it and the site compares its signing date. So the number, the year and the type are
     * compared here, on each act.
     */
    public function keeps(PortalAct $act): bool
    {
        if ($this->number !== null && $act->number !== (string) $this->number) {
            return false;
        }

        if ($this->year !== null && $act->year() !== $this->year) {
            return false;
        }

        return $this->type === null || $act->typeKey() === $this->type;
    }

    /** @return array{words: ?string, number: ?int, year: ?int, type: ?string} */
    public function toArray(): array
    {
        return ['words' => $this->words, 'number' => $this->number, 'year' => $this->year, 'type' => $this->type];
    }

    private static function typeKey(?string $type): ?string
    {
        return $type === null || trim($type) === '' ? null : PortalAct::key($type);
    }
}
