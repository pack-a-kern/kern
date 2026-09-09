<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\CandidateExtraction;

use App\Domain\TaxKnowledge\CandidateExtraction\DataTransferObjects\CandidateChange;
use App\Domain\TaxKnowledge\CandidateExtraction\DataTransferObjects\CandidateChangeSet;
use App\Domain\TaxKnowledge\CandidateExtraction\DataTransferObjects\Citation;
use App\Domain\TaxKnowledge\CandidateExtraction\Exceptions\InvalidCandidateChangeSetException;
use DateTimeImmutable;
use JsonException;

/**
 * Decodes and strictly validates one `BLUEPRINT.md` §9.2 AI candidate-extraction payload. This is
 * the whole boundary this task was asked to build: the JSON shape, strict validation, mandatory
 * citations, and confidence — nothing calls an LLM here (ADR 0007, provider undecided,
 * `apps/processing`'s job), and nothing here persists a row. See
 * {@see \App\Domain\TaxKnowledge\CandidateExtraction\CandidateCitationVerifier} for the
 * database-backed check that citations actually exist.
 *
 * Every violation throws {@see InvalidCandidateChangeSetException} — malformed JSON, a missing or
 * wrong-typed field, a candidate change with zero citations, a malformed `quote_hash`, an
 * out-of-range `confidence`, or `effective_until` before `effective_from`. Nothing is accepted
 * "best effort" (`docs/AGENTS.md` §1.14).
 */
final class CandidateChangeSetParser
{
    public static function fromJson(string $json): CandidateChangeSet
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidCandidateChangeSetException::because('not valid JSON: ' . $exception->getMessage());
        }

        return self::fromArray($decoded);
    }

    public static function fromArray(mixed $decoded): CandidateChangeSet
    {
        if (! is_array($decoded)) {
            throw InvalidCandidateChangeSetException::because('top level must be a JSON object.');
        }

        $documentVersionId = self::requireNonEmptyString($decoded, 'document_version_id');
        $rawCandidateChanges = $decoded['candidate_changes'] ?? null;

        if (! is_array($rawCandidateChanges)) {
            throw InvalidCandidateChangeSetException::because('"candidate_changes" must be an array.');
        }

        $candidateChanges = [];
        foreach ($rawCandidateChanges as $index => $rawChange) {
            if (! is_array($rawChange)) {
                throw InvalidCandidateChangeSetException::because("candidate_changes[{$index}] must be an object.");
            }

            $candidateChanges[] = self::parseCandidateChange($rawChange, (string) $index);
        }

        return new CandidateChangeSet($documentVersionId, $candidateChanges);
    }

    /**
     * @param array<array-key, mixed> $change
     */
    private static function parseCandidateChange(array $change, string $path): CandidateChange
    {
        $topic = self::requireNonEmptyString($change, 'topic', $path);
        $subjectTypes = self::requireStringList($change, 'subject_types', $path);
        $incomeCategories = self::requireStringList($change, 'income_categories', $path);
        $effectiveFrom = self::requireDate($change, 'effective_from', $path);
        $effectiveUntil = self::optionalDate($change, 'effective_until', $path);

        if ($effectiveUntil !== null && $effectiveUntil < $effectiveFrom) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].effective_until precedes effective_from.");
        }

        $parameterChanges = $change['parameter_changes'] ?? null;

        if (! is_array($parameterChanges)) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].parameter_changes must be an array.");
        }

        /** @var list<array<string, mixed>> $parameterChangesList */
        $parameterChangesList = array_values($parameterChanges);

        $ruleChangeSummary = self::requireNonEmptyString($change, 'rule_change_summary', $path);
        $citations = self::parseCitations($change, $path);
        $confidence = self::requireConfidence($change, $path);

        return new CandidateChange(
            topic: $topic,
            subjectTypes: $subjectTypes,
            incomeCategories: $incomeCategories,
            effectiveFrom: $effectiveFrom,
            effectiveUntil: $effectiveUntil,
            parameterChanges: $parameterChangesList,
            ruleChangeSummary: $ruleChangeSummary,
            citations: $citations,
            confidence: $confidence,
        );
    }

    /**
     * @param array<array-key, mixed> $change
     * @return list<Citation>
     */
    private static function parseCitations(array $change, string $path): array
    {
        $rawCitations = $change['citations'] ?? null;

        if (! is_array($rawCitations) || $rawCitations === []) {
            throw InvalidCandidateChangeSetException::because(
                "candidate_changes[{$path}].citations must be a non-empty array — every candidate change needs at least one source citation.",
            );
        }

        $citations = [];

        foreach ($rawCitations as $index => $rawCitation) {
            if (! is_array($rawCitation)) {
                throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].citations[{$index}] must be an object.");
            }

            $sectionId = self::requireNonEmptyString($rawCitation, 'section_id', "{$path}.citations[{$index}]");
            $quoteHash = self::requireNonEmptyString($rawCitation, 'quote_hash', "{$path}.citations[{$index}]");

            if (preg_match('/^[0-9a-f]{64}$/', $quoteHash) !== 1) {
                throw InvalidCandidateChangeSetException::because(
                    "candidate_changes[{$path}].citations[{$index}].quote_hash must be a 64-character lowercase hex SHA-256 digest.",
                );
            }

            $citations[] = new Citation($sectionId, $quoteHash);
        }

        return $citations;
    }

    /**
     * @param array<array-key, mixed> $change
     */
    private static function requireConfidence(array $change, string $path): float
    {
        $confidence = $change['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].confidence must be a number.");
        }

        $confidence = (float) $confidence;

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].confidence must be between 0 and 1.");
        }

        return $confidence;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireNonEmptyString(array $data, string $key, string $path = ''): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            $label = $path === '' ? $key : "candidate_changes[{$path}].{$key}";

            throw InvalidCandidateChangeSetException::because("\"{$label}\" must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private static function requireStringList(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].{$key} must be an array.");
        }

        $strings = [];
        foreach ($value as $index => $entry) {
            if (! is_string($entry) || $entry === '') {
                throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].{$key}[{$index}] must be a non-empty string.");
            }

            $strings[] = $entry;
        }

        return $strings;
    }

    /**
     * @param array<array-key, mixed> $change
     */
    private static function requireDate(array $change, string $key, string $path): DateTimeImmutable
    {
        $date = self::optionalDate($change, $key, $path);

        if ($date === null) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].{$key} is required.");
        }

        return $date;
    }

    /**
     * @param array<array-key, mixed> $change
     */
    private static function optionalDate(array $change, string $key, string $path): ?DateTimeImmutable
    {
        if (! array_key_exists($key, $change) || $change[$key] === null) {
            return null;
        }

        $value = $change[$key];

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].{$key} must be a \"YYYY-MM-DD\" date string.");
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false) {
            throw InvalidCandidateChangeSetException::because("candidate_changes[{$path}].{$key} is not a valid calendar date.");
        }

        return $date;
    }
}
