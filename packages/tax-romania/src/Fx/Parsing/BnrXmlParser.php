<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Parsing;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Kern\TaxRomania\Fx\DataTransferObjects\ParsedFxDay;
use Kern\TaxRomania\Fx\DataTransferObjects\ParsedFxRate;
use Kern\TaxRomania\Fx\Parsing\Exceptions\InvalidBnrXmlException;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

/**
 * Parses a BNR exchange-rate XML document (`BLUEPRINT.md` §7) into one {@see ParsedFxDay} per
 * `<Cube date="...">` element — a daily feed has exactly one, a yearly archive has one per
 * banking day.
 *
 * **This schema is UNVERIFIED.** `docs/reference/bnr-fx-endpoints.md` establishes that every
 * documented BNR XML URL currently 302-redirects to the homepage, so no real response has been
 * observed this session. The `DataSet/Body/Cube/Rate` shape with a `multiplier` attribute
 * assumed here reflects `BLUEPRINT.md` §7's own narrative description (daily rates, a multiplier for
 * currencies quoted per more than one unit) plus the widely publicly documented shape of BNR's
 * historical XML feed — **not a captured response**. This parser is deliberately
 * **namespace-agnostic** (`local-name()` XPath predicates, matching neither a specific element
 * prefix nor absence of one) specifically because the exact namespace is one of the unverified
 * details, and getting it wrong should not be what breaks parsing once a real feed is confirmed.
 * Re-verify this class's fixture assumptions in `tests/Fixtures/Fx/Bnr/` against a real captured
 * response before this importer is ever pointed at a live BNR URL — see
 * `docs/handoff/014-source-registry-bnr.md` for exactly what to re-check.
 */
final class BnrXmlParser
{
    /**
     * @return list<ParsedFxDay>
     */
    public function parse(string $xml): array
    {
        $dom = $this->loadDocument($xml);
        $xpath = new DOMXPath($dom);

        $cubeNodes = $xpath->query('//*[local-name()="Cube"][@date]');

        if ($cubeNodes === false || $cubeNodes->length === 0) {
            throw InvalidBnrXmlException::noCubeElements();
        }

        $days = [];
        $seenDates = [];

        foreach ($cubeNodes as $cube) {
            if (! $cube instanceof DOMElement) {
                continue;
            }

            $dateAttribute = $cube->getAttribute('date');
            $date = $this->parseDate($dateAttribute);

            if (isset($seenDates[$dateAttribute])) {
                throw InvalidBnrXmlException::duplicateDate($dateAttribute);
            }

            $seenDates[$dateAttribute] = true;

            $days[] = new ParsedFxDay($date, $this->parseRates($xpath, $cube, $dateAttribute));
        }

        return $days;
    }

    private function loadDocument(string $xml): DOMDocument
    {
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET: never resolve an external entity/DTD over the network, even though
        // PHP 8's libxml defaults already disable external entity substitution — defense in
        // depth against XXE for a document fetched from the network (docs/AGENTS.md §8, applied
        // generally, not just to PII).
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);

        if (! $loaded) {
            $errors = libxml_get_errors();
            libxml_clear_errors();

            throw InvalidBnrXmlException::malformed($errors === [] ? 'unknown parse error' : trim($errors[0]->message));
        }

        return $dom;
    }

    private function parseDate(string $dateAttribute): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateAttribute);

        if ($date === false || $date->format('Y-m-d') !== $dateAttribute) {
            throw InvalidBnrXmlException::invalidDate($dateAttribute);
        }

        return $date;
    }

    /**
     * @return list<ParsedFxRate>
     */
    private function parseRates(DOMXPath $xpath, DOMElement $cube, string $dateAttribute): array
    {
        $rateNodes = $xpath->query('.//*[local-name()="Rate"]', $cube);

        if ($rateNodes === false || $rateNodes->length === 0) {
            throw InvalidBnrXmlException::noRatesForDate($dateAttribute);
        }

        $rates = [];
        $seenCurrencies = [];

        foreach ($rateNodes as $rateNode) {
            if (! $rateNode instanceof DOMElement) {
                continue;
            }

            $currency = strtoupper(trim($rateNode->getAttribute('currency')));

            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw InvalidBnrXmlException::invalidCurrencyCode($currency, $dateAttribute);
            }

            if (isset($seenCurrencies[$currency])) {
                throw InvalidBnrXmlException::duplicateCurrency($currency, $dateAttribute);
            }

            $seenCurrencies[$currency] = true;

            $rates[] = new ParsedFxRate(
                $currency,
                $this->parseMultiplier($rateNode, $currency, $dateAttribute),
                $this->parseRateValue($rateNode, $currency, $dateAttribute),
            );
        }

        return $rates;
    }

    private function parseMultiplier(DOMElement $rateNode, string $currency, string $dateAttribute): int
    {
        $attribute = $rateNode->hasAttribute('multiplier') ? trim($rateNode->getAttribute('multiplier')) : '1';

        if ($attribute === '' || ! ctype_digit($attribute) || (int) $attribute < 1) {
            throw InvalidBnrXmlException::invalidMultiplier($currency, $attribute, $dateAttribute);
        }

        return (int) $attribute;
    }

    private function parseRateValue(DOMElement $rateNode, string $currency, string $dateAttribute): DecimalRate
    {
        $raw = trim($rateNode->textContent);

        if (! preg_match('/^\d+(\.\d+)?$/', $raw)) {
            throw InvalidBnrXmlException::invalidRateValue($currency, $dateAttribute);
        }

        return DecimalRate::fromString($raw);
    }
}
