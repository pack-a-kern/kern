<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

use App\Domain\Settings\Enums\AiDialect;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Domain\Settings\Services\ResolveAiProviderCredentials;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\Provision;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterAnswer;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterDefinition;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;

/**
 * One question put to a model about the law, and the answer checked against the law itself.
 *
 * The model is given the paragraphs closest to the question — never the whole chapter, which is
 * where it starts leaving things out — and answers with the numbers and the label of the paragraph
 * that states them. Two checks then decide whether that answer exists at all:
 *
 *  1. the paragraph it names is one of those it was given;
 *  2. every number it gives stands in that paragraph, read with the lettered points under it, as
 *     the law writes a rule across them: "baza o reprezintă: a) 6 salarii … b) 12 salarii".
 *
 * An answer failing either is not a value and is not recorded. The law's own words are never asked
 * for — this application holds them already, and a sentence copied back is the same sentence
 * mistyped. What the checks cannot catch is a model answering about the wrong thing from the right
 * paragraph, which is why a second reader can be asked and a person approves.
 */
final readonly class LawReader
{
    public function __construct(
        private HttpFactory $http,
        private AiProviderCatalogue $catalogue,
        private ResolveAiProviderCredentials $credentials,
        private ConfigRepository $config,
    ) {
    }

    /** Whether the law can be read at all: a provider configured, enabled, with a model. */
    public function isAvailable(): bool
    {
        $credentials = ($this->credentials)();

        return $credentials->enabled && $credentials->baseUrl !== null;
    }

    /**
     * The models that read, in order: the reader, and the one that reads after it where a second is
     * named. Naming none is one reading and one call — the person approving is the check.
     *
     * @return list<string>
     */
    public function readers(): array
    {
        $reader = $this->config->string('ai.legislation.reader');
        $reader = $reader !== '' ? $reader : (string) ($this->credentials)()->model;
        $second = $this->config->string('ai.legislation.second_reader');

        return array_values(array_unique(array_filter([$reader, $second], static fn (string $model): bool => $model !== '')));
    }

    /**
     * What one model makes of these paragraphs — and whether one was reached at all.
     *
     * @param  list<Provision>  $provisions
     */
    public function read(ParameterDefinition $definition, array $provisions, DateTimeImmutable $on, string $model): LawReading
    {
        if ($provisions === [] || ! $this->isAvailable()) {
            return LawReading::unreached();
        }

        $sent = $this->paragraphs($provisions);
        $reply = $this->ask($this->instruction($definition, $on), $sent, $model);

        return $reply === null ? LawReading::unreached() : LawReading::of($this->verify($definition, $provisions, $sent, $reply, $model));
    }

    /**
     * What one model makes of these paragraphs, asked every question of one tax at once.
     *
     * The rules of a tax are read from the same part of the law, so one page of it answers all of
     * them. Each answer is checked on its own, exactly as a single one is, and a question the reply
     * leaves out or answers with something the law does not hold simply has no answer here — the
     * caller asks that one again by itself.
     *
     * @param  list<ParameterDefinition>  $definitions
     * @param  list<Provision>  $provisions
     * @return array{answers: array<string, LawAnswer>, reached: bool} what each question was answered, by the code of its value
     */
    public function readTogether(array $definitions, array $provisions, DateTimeImmutable $on, string $model): array
    {
        if ($definitions === [] || $provisions === [] || ! $this->isAvailable()) {
            return ['answers' => [], 'reached' => false];
        }

        $sent = $this->paragraphs($provisions);
        $reply = $this->ask($this->instructionTogether($definitions, $on), $sent, $model);
        $read = [];

        foreach ($definitions as $definition) {
            /** @var array<string, mixed>|null $answer */
            $answer = is_array($reply[$definition->code] ?? null) ? $reply[$definition->code] : null;
            $found = $answer === null ? null : $this->verify($definition, $provisions, $sent, $answer, $model);

            if ($found !== null) {
                $read[$definition->code] = $found;
            }
        }

        return ['answers' => $read, 'reached' => $reply !== null];
    }

    /**
     * The answer as the law itself confirms it, or null.
     *
     * @param  list<Provision>  $provisions
     * @param  string  $sent  the paragraphs as the reader was given them
     * @param  array<string, mixed>  $reply
     */
    private function verify(ParameterDefinition $definition, array $provisions, string $sent, array $reply, string $model): ?LawAnswer
    {
        $values = $this->numbers($definition, $reply);
        $citations = $this->citations($definition, $reply);

        if ($values === [] || $citations === []) {
            return null;
        }

        $said = [];

        foreach ($citations as $at => $citation) {
            $paragraph = $this->paragraph($citation, $provisions);

            if ($paragraph === null || ! $this->states($paragraph, $definition->answer->isRange() ? [$values[$at] ?? $values[0]] : $values)) {
                return null;
            }

            $said[] = $paragraph;
        }

        return new LawAnswer($values, $said[0]->sectionId, $said[0]->citation, end($said)->citation, $model);
    }

    /**
     * Whether a paragraph states every one of these numbers, read with the lettered points under
     * it: the law writes a rule as "baza o reprezintă:" and its numbers in the points below.
     *
     * @param  list<int>  $values
     */
    private function states(Provision $paragraph, array $values): bool
    {
        $text = self::digits(SectionText::searchable($paragraph->text . ' ' . $paragraph->under));

        foreach ($values as $value) {
            if (preg_match('/\b' . preg_quote((string) $value, '/') . '\b/', $text) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The paragraph a reader named, among those it was given.
     *
     * @param  list<Provision>  $provisions
     */
    private function paragraph(string $citation, array $provisions): ?Provision
    {
        foreach ($provisions as $provision) {
            if ($provision->citation === $citation) {
                return $provision;
            }
        }

        return null;
    }

    /**
     * The paragraphs a reader named: one, or one per end of a value with two.
     *
     * @param  array<string, mixed>  $reply
     * @return list<string>
     */
    private function citations(ParameterDefinition $definition, array $reply): array
    {
        $named = $definition->answer->isRange()
            ? [$reply['floor_citation'] ?? null, $reply['cap_citation'] ?? null]
            : [$reply['citation'] ?? null];
        $citations = array_values(array_map('trim', array_filter($named, static fn (mixed $citation): bool => is_string($citation) && trim($citation) !== '')));

        return count($citations) === count($named) ? $citations : [];
    }

    /**
     * The numbers of an answer, as the law writes them: a rate in percent — 10 for 10%, which is
     * stored as 0.10 — wages and lei as stated.
     *
     * @param  array<string, mixed>  $reply
     * @return list<int>
     */
    private function numbers(ParameterDefinition $definition, array $reply): array
    {
        $given = match ($definition->answer) {
            ParameterAnswer::WAGES_LIST => is_array($reply['values'] ?? null) ? $reply['values'] : [],
            ParameterAnswer::WAGES_RANGE => [$reply['floor'] ?? null, $reply['cap'] ?? null],
            default => [$reply['value'] ?? null],
        };
        $said = array_values(array_filter($given, static fn (mixed $value): bool => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))));
        $numbers = array_map(static fn (int|float|string $value): int => (int) round((float) $value), $said);

        if ($numbers === [] || count($numbers) !== count($given)) {
            return [];
        }

        // A range is the floor and the cap, in that order; everything else reads smallest first.
        if (! $definition->answer->isRange()) {
            sort($numbers);
        }

        return $numbers;
    }

    /** A text with the law's own thousands separators taken out, so "3.000 lei" holds the number 3000. */
    private static function digits(string $text): string
    {
        return (string) preg_replace('/(?<=\d)[.\s](?=\d{3}(?!\d))/u', '', $text);
    }

    /** @param list<Provision> $provisions */
    private function paragraphs(array $provisions): string
    {
        return implode("\n", array_map(
            static fn (Provision $provision): string => $provision->citation . ' ' . trim((string) preg_replace('/\s+/u', ' ', $provision->text)),
            $provisions,
        ));
    }

    /**
     * One instruction carrying every question of a tax, and the shape of the reply: a value, a
     * paragraph and a quote under each value's own code.
     *
     * @param  list<ParameterDefinition>  $definitions
     */
    private function instructionTogether(array $definitions, DateTimeImmutable $on): string
    {
        $questions = [];
        $shape = [];

        foreach ($definitions as $definition) {
            $questions[] = '- ' . $definition->code . ': ' . (string) $definition->question . ' (' . $definition->answer->unit() . ')';
            $shape[] = '    "' . $definition->code . '": ' . $definition->answer->shape();
        }

        return str_replace(
            ['{date}', '{questions}', '{shape}'],
            [$on->format('d.m.Y'), implode("\n", $questions), "{\n" . implode(",\n", $shape) . "\n}"],
            $this->config->string('ai.legislation.instruction_together'),
        );
    }

    private function instruction(ParameterDefinition $definition, DateTimeImmutable $on): string
    {
        return str_replace(
            ['{date}', '{question}', '{unit}', '{shape}'],
            [$on->format('d.m.Y'), (string) $definition->question, $definition->answer->unit(), $definition->answer->shape()],
            $this->config->string('ai.legislation.instruction'),
        );
    }

    /**
     * One call, and the JSON object of its reply — or null when the provider refuses, the model
     * runs out of room, or what comes back is not one object.
     *
     * @return array<string, mixed>|null
     */
    private function ask(string $instruction, string $paragraphs, string $model): ?array
    {
        $credentials = ($this->credentials)();
        $baseUrl = $credentials->baseUrl;

        if ($baseUrl === null) {
            return null;
        }

        $dialect = $this->catalogue->dialectFor($credentials->provider);
        $body = $dialect->extractionBody(
            model: $model,
            systemPrompt: $this->config->string('ai.legislation.system'),
            instruction: $instruction,
            documentText: $paragraphs,
            images: [],
            maxTokens: $this->config->integer('ai.legislation.max_tokens'),
        );
        $headers = $credentials->apiKey === null
            ? $dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion())
            : $dialect->authHeaders($credentials->apiKey, $this->catalogue->anthropicVersion());

        try {
            $response = $this->http
                ->timeout($this->config->integer('ai.legislation.timeout_seconds'))
                ->withHeaders($headers)
                ->post(rtrim($baseUrl, '/') . $dialect->chatPath(), $body);
        } catch (ConnectionException) {
            return null;
        }

        $reply = $response->successful() ? data_get($response->json(), $dialect->replyTextPath()) : null;

        return is_string($reply) ? $this->decode($reply) : null;
    }

    /**
     * The object a reply carries, whether it is the whole reply or fenced in markdown.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $reply): ?array
    {
        $json = trim((string) preg_replace('/^\s*```(?:json)?|```\s*$/m', '', $reply));
        $start = strpos($json, '{');
        $end = strrpos($json, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        try {
            /**  */
            $decoded = json_decode(substr($json, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /** Kept for the dialect the provider speaks, so a reading does not resolve it per call. */
    public function dialect(): AiDialect
    {
        return $this->catalogue->dialectFor(($this->credentials)()->provider);
    }
}
