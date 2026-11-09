<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Reading;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Kern\Legislation\Parsing\SectionText;
use Kern\Platform\Settings\Enums\AiDialect;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;
use Kern\TaxRomania\FiscalParameters\Matching\Provision;
use Kern\TaxRomania\FiscalParameters\ParameterAnswer;
use Kern\TaxRomania\FiscalParameters\ParameterDefinition;
use Psr\Log\LoggerInterface;

/**
 * One question put to a model about the law, and the answer checked against the law itself.
 *
 * The model is given the paragraphs closest to the question — never the whole chapter, which is
 * where it starts leaving things out — and answers with the numbers and the label of the paragraph
 * that states them. Two checks then decide whether that answer exists at all:
 *
 *  1. the paragraph it names is one of those it was given;
 *  2. every number it gives stands in that paragraph, read with the lettered points under it, as
 *     the law writes a rule across them: "baza o reprezintă: a) 6 salarii … b) 12 salarii". A yes
 *     or a no has no number, and rests on the first check alone.
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
        private LoggerInterface $log,
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
        $reply = $this->ask($this->instruction($definition, $on), $sent, $model, [
            'asked' => $definition->code,
            'on' => $on->format('Y-m-d'),
            'provisions' => count($provisions),
        ]);

        if ($reply === null) {
            return LawReading::unreached();
        }

        $answer = $this->verify($definition, $provisions, $sent, $reply, $model);
        $this->refused($definition, $answer, $reply);

        return LawReading::of($answer);
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
        $reply = $this->ask($this->instructionTogether($definitions, $on), $sent, $model, [
            'asked' => implode(', ', array_map(static fn (ParameterDefinition $definition): string => $definition->code, $definitions)),
            'on' => $on->format('Y-m-d'),
            'provisions' => count($provisions),
        ]);
        $read = [];

        foreach ($definitions as $definition) {
            /** @var array<string, mixed>|null $answer */
            $answer = is_array($reply[$definition->code] ?? null) ? $reply[$definition->code] : null;
            $found = $answer === null ? null : $this->verify($definition, $provisions, $sent, $answer, $model);

            if ($found !== null) {
                $read[$definition->code] = $found;
            }

            if ($answer !== null) {
                $this->refused($definition, $found, $answer);
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
        $range = $definition->answer->isRangeIn($reply);
        $values = $this->numbers($definition, $reply, $range);
        $citations = $this->citations($definition, $reply, $range);

        if ($values === [] || $citations === []) {
            return null;
        }

        $said = [];

        foreach ($citations as $at => $citation) {
            $paragraph = $this->paragraph($citation, $provisions);

            // A range stands on two paragraphs, a number each; every other answer states all of its
            // numbers in the one paragraph it names.
            // A day is stated with its month's name; every other number as a number. A yes or a no
            // has no number to find: the paragraph it names, among those given, is what it rests on.
            if ($paragraph === null || ! match (true) {
                $definition->answer->isYesOrNo() => true,
                $definition->answer->isDayAndMonth() => $this->statesDay($paragraph, $values[0], $values[1] ?? 0),
                default => $this->states($paragraph, $range ? [$values[$at] ?? $values[0]] : $values),
            }) {
                return null;
            }

            $said[] = $paragraph;
        }

        return new LawAnswer($values, $said[0]->sectionId, $said[0]->citation, end($said)->citation, $model, $range);
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
     * Whether a paragraph states this day of this month, the way the law writes a date: the day as a
     * number and the month by its name — "25 mai". A month is matched by any of its names in
     * `config/reference/months.php`, compared as {@see SectionText::searchable()} writes the text.
     */
    private function statesDay(Provision $paragraph, int $day, int $month): bool
    {
        $names = array_keys(array_filter($this->config->array('reference.months'), static fn (mixed $number): bool => $number === $month));

        if ($names === []) {
            return false;
        }

        $alternatives = implode('|', array_map(static fn (int|string $name): string => preg_quote((string) $name, '/'), $names));

        return preg_match('/\b' . $day . '\s+(?:' . $alternatives . ')\b/', SectionText::searchable($paragraph->text . ' ' . $paragraph->under)) === 1;
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
    private function citations(ParameterDefinition $definition, array $reply, bool $range): array
    {
        $named = $range
            ? [$reply['floor_citation'] ?? null, $reply['cap_citation'] ?? null]
            : [$reply['citation'] ?? null];
        $citations = array_values(array_map('trim', array_filter($named, static fn (mixed $citation): bool => is_string($citation) && trim($citation) !== '')));

        return count($citations) === count($named) ? $citations : [];
    }

    /**
     * The numbers of an answer, as the law writes them: a rate in percent — 10 for 10%, which is
     * stored as 0.10 — wages, lei and days as stated, a yes as 1 and a no as 0.
     *
     * @param  array<string, mixed>  $reply
     * @return list<int>
     */
    private function numbers(ParameterDefinition $definition, array $reply, bool $range): array
    {
        // A reply that names one shape and answers in the other is not an answer: "steps" with a
        // floor, or an interval with a list, is a reader that has not decided what it read.
        if ($definition->answer->isEither() && ($range === is_array($reply['values'] ?? null))) {
            return [];
        }

        $given = match (true) {
            $range => [$reply['floor'] ?? null, $reply['cap'] ?? null],
            $definition->answer === ParameterAnswer::WAGES_LIST, $definition->answer->isEither() => is_array($reply['values'] ?? null) ? $reply['values'] : [],
            $definition->answer->isDayAndMonth() => [$reply['day'] ?? null, $reply['month'] ?? null],
            // A yes or a no is a boolean and nothing else: a 1 or a "da" is a reader that has not
            // answered the question asked.
            $definition->answer->isYesOrNo() => [is_bool($reply['value'] ?? null) ? (int) $reply['value'] : null],
            default => [$reply['value'] ?? null],
        };
        $said = array_values(array_filter($given, static fn (mixed $value): bool => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))));
        $numbers = array_map(static fn (int|float|string $value): int => (int) round((float) $value), $said);

        if ($numbers === [] || count($numbers) !== count($given)) {
            return [];
        }

        // A range is the floor and the cap, in that order, and a day comes before its month;
        // everything else reads smallest first.
        if (! $range && ! $definition->answer->isDayAndMonth()) {
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
     * What a reply said, where nothing in it was a value.
     *
     * An answer is refused for reasons the law can settle — a paragraph that was never sent, a
     * number that does not stand in it, a citation left out where two are asked for — and the reply
     * is the only thing that says which. Kept at debug, and only when refused: what it holds is
     * numbers and the label of a paragraph of a public law, never a person's anything.
     *
     * @param  array<string, mixed>  $reply
     */
    private function refused(ParameterDefinition $definition, ?LawAnswer $answer, array $reply): void
    {
        if ($answer !== null) {
            return;
        }

        $this->log->debug('legislation.refused', [
            'asked' => $definition->code,
            'said' => json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    }

    /**
     * One call, and the JSON object of its reply — or null when the provider refuses, the model
     * runs out of room, or what comes back is not one object.
     *
     * Every call is logged, before and after: reading a year is a handful of calls whose number is
     * not obvious from the outside — one per tax, one more for each question the first reply left
     * unanswered, one for each day the law changed under a rule — and a reading that sits for
     * minutes is one of them waiting on the model. The line before says which is out; the line
     * after says how long it took and whether anything came back. Nothing of the law is logged:
     * what is sent is public text this application already holds, and the counts locate the call.
     *
     * @param  array{asked: string, on: string, provisions: int}  $about  which question, in the law of which day
     * @return array<string, mixed>|null
     */
    private function ask(string $instruction, string $paragraphs, string $model, array $about): ?array
    {
        $credentials = ($this->credentials)();
        $baseUrl = $credentials->baseUrl;

        if ($baseUrl === null) {
            return null;
        }

        $context = [...$about, 'model' => $model, 'characters' => mb_strlen($paragraphs)];
        $started = microtime(true);
        $this->log->debug('legislation.asking', $context);

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

        $said = function (string $reply) use ($context, $started): void {
            $this->log->info('legislation.read', [...$context, 'seconds' => round(microtime(true) - $started, 1), 'reply' => $reply]);
        };

        try {
            $response = $this->http
                ->timeout($this->config->integer('ai.legislation.timeout_seconds'))
                ->withHeaders($headers)
                ->post(rtrim($baseUrl, '/') . $dialect->chatPath(), $body);
        } catch (ConnectionException) {
            $said('unreachable');

            return null;
        }

        $reply = $response->successful() ? data_get($response->json(), $dialect->replyTextPath()) : null;
        $read = is_string($reply) ? $this->decode($reply) : null;
        $said(match (true) {
            ! $response->successful() => 'refused ' . $response->status(),
            $read !== null => 'read',
            default => 'unreadable',
        });

        return $read;
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
