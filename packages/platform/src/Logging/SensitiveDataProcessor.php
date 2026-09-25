<?php

declare(strict_types=1);

namespace Kern\Platform\Logging;

use function array_is_list;
use function array_map;
use function get_object_vars;

use Illuminate\Contracts\Support\Arrayable;

use function in_array;
use function is_array;
use function is_object;
use function is_string;

use JsonSerializable;

use function mb_strtolower;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

use function preg_replace;
use function preg_replace_callback;
use function spl_object_id;

use Stringable;
use Throwable;

/**
 * Redacts passwords, tokens/API keys, `Authorization` headers, IBANs, OTP codes — and whatever
 * else this installation declared through {@see Redactions} — from
 * every log record before it reaches a handler — `BLUEPRINT.md` §4.10 ("no plain PII logs") and §23
 * ("niciun PII" in structured logs), enforced in code rather than by author discipline alone.
 *
 * Wired via `config/logging.php`'s `tap` option ({@see RedactSensitiveData}) rather than a
 * channel's `processors` key: Laravel only reads `processors` for the `monolog` driver
 * (`Illuminate\Log\LogManager::createMonologDriver()`), but `tap` is applied uniformly to every
 * driver (`LogManager::get()`), which is what lets this run on `single`/`daily`/`stderr`/etc.
 *
 * ## Two redaction strategies, applied together
 *
 * - **By key name** ({@see self::SENSITIVE_KEYS}): a context/extra array entry whose key
 *   normalises (lower-cased, non-alphanumerics stripped) to a known sensitive name has its
 *   *entire* value replaced with `[REDACTED]`, recursively, regardless of the value's shape — an
 *   object or nested array under `password` is not selectively inspected, it is simply gone.
 * - **By pattern** ({@see self::redactPatterns()}): every string leaf value, and the log message
 *   itself, is scanned for IBAN-shaped, JWT-shaped, `Authorization:` header, declared-shape, and
 *   inline-OTP substrings, independent of what key (if any) it was found under. This is what
 *   catches a secret embedded in free text, e.g. an exception message.
 *
 * ## Ordering with `PsrLogMessageProcessor`
 *
 * This processor is pushed via `Logger::pushProcessor()` (in the `tap` callback), which
 * `Monolog\Logger` prepends to its processor stack (`array_unshift`) and runs front-to-back — so
 * this processor always runs *before* `PsrLogMessageProcessor` (added by Laravel's
 * `'replace_placeholders' => true` channel config as part of the initial Monolog construction, and
 * therefore always behind anything `pushProcessor()` adds later). That order matters: it means
 * context values are already redacted by the time `{placeholder}` substitution copies them into
 * the message string, so a call like `Log::info('login failed for {password}', ['password' =>
 * $raw])` never leaks `$raw` into the formatted message either.
 *
 * ## Known limitation — exception objects
 *
 * A `Throwable` under the conventional `context['exception']` key is replaced with a small,
 * redacted array (class, redacted message, file, line) rather than left as the live object —
 * deliberately: Monolog's own exception formatting calls `getTraceAsString()`, and a stack trace
 * can carry full argument values from every frame, which may include PII this processor has no
 * way to inspect generically. The trade-off is an intentionally poorer stack trace in logs in
 * exchange for not leaking arguments. If a call site needs full traces for debugging, it must log
 * them to a channel with independent, narrower access controls — never assume this processor makes
 * that safe by default.
 */
final class SensitiveDataProcessor implements ProcessorInterface
{
    private const string REDACTED = '[REDACTED]';

    /**
     * Key names (normalised: lower-cased, non-alphanumeric characters stripped) whose value is
     * always fully redacted, whatever shape it is.
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEYS = [
        // Passwords / secrets.
        'password', 'pwd', 'pass', 'passwd', 'secret', 'clientsecret', 'secretkey', 'privatekey',
        // Tokens / API keys / auth headers.
        'token', 'accesstoken', 'refreshtoken', 'apikey', 'apitoken', 'bearertoken', 'authtoken',
        'authorization',
        // A bank account is an international shape, and so is a one-time code.
        'iban',
        'otp', 'otpcode', 'safetycode', 'verificationcode', 'mfacode', 'totp', 'totpcode',
    ];

    /*
     * A person's or a company's own identifier is not here, and that is the point: a CNP is thirteen
     * digits starting 1-8 and a CUI is a Romanian fiscal code, so both are declared by the package
     * that knows what those are, through {@see Redactions}. This one carries only the shapes that
     * mean the same thing in any application.
     */

    /** IBAN: 2 letters + 2 check digits + up to 30 alphanumerics, optionally space-grouped. */
    private const string IBAN_PATTERN = '/\b[A-Za-z]{2}\d{2}(?:[ ]?[A-Za-z0-9]{4}){2,7}\b/';

    /** JWT-shaped bearer token: three dot-separated base64url segments. */
    private const string JWT_PATTERN = '/\b[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/';

    /** `Authorization: Bearer <...>` / `Authorization: Basic <...>` appearing in free text. */
    private const string AUTHORIZATION_HEADER_PATTERN = '/\b(Authorization)\s*:\s*(Bearer|Basic)\s+\S+/i';

    /**
     * `password=Sup3rSecret!`, `pwd: hunter2`, `secret=...` style inline assignments in free
     * text — e.g. an exception message built with string interpolation rather than a structured
     * context array, which key-name-based redaction in {@see self::isSensitiveKey()} cannot see.
     */
    private const string INLINE_KEY_VALUE_SECRET_PATTERN = '/\b(password|pwd|passwd|secret|token|api[_-]?key|client[_-]?secret)\s*[:=]\s*(\S+)/i';

    /**
     * `OTP: 123456` / `verification code 123456` style inline codes in free text.
     *
     * The Romanian phrasing ANAF uses — "cod de siguranță" — is declared by `kern/tax-romania`
     * through {@see Redactions}, along with the identifiers only it can recognise.
     */
    private const string OTP_INLINE_PATTERN = '/\b(OTP|otp|verification\s+code)\s*[:=]?\s*\d{4,8}\b/iu';

    public function __construct(private readonly Redactions $declared)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactPatterns($record->message),
            context: $this->redactStructure($record->context),
            extra: $this->redactStructure($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<int, true>|null $seen object ids already visited on the current path
     * @return array<array-key, mixed>
     */
    private function redactStructure(array $data, int $depth = 0, ?array $seen = null): array
    {
        $seen ??= [];

        if ($depth > 8) {
            return [self::REDACTED . ':max-depth'];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = $this->redactValue($value, $depth, $seen);
        }

        return $result;
    }

    /**
     * @param array<int, true> $seen object ids already visited on the current path, to break cycles
     */
    private function redactValue(mixed $value, int $depth, array $seen): mixed
    {
        if (is_string($value)) {
            return $this->redactPatterns($value);
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $this->redactPatterns($value->getMessage()),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->redactValue($item, $depth + 1, $seen), $value);
            }

            return $this->redactStructure($value, $depth + 1, $seen);
        }

        if (is_object($value)) {
            $objectId = spl_object_id($value);

            if (isset($seen[$objectId])) {
                return self::REDACTED . ':circular-reference';
            }

            $seen[$objectId] = true;

            return $this->redactStructure($this->objectToArray($value), $depth + 1, $seen);
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function objectToArray(object $value): array
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $serialized : ['value' => $serialized];
        }

        if ($value instanceof Stringable) {
            return ['value' => (string) $value];
        }

        return get_object_vars($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', mb_strtolower($key)) ?? '';

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || in_array($normalized, $this->declared->declaredKeys(), true);
    }

    private function redactPatterns(string $value): string
    {
        // Runs first: a password/token *value* embedded in free text could otherwise itself
        // happen to look like a CNP, IBAN, or JWT and only get partially redacted by those.
        $value = preg_replace_callback(
            self::INLINE_KEY_VALUE_SECRET_PATTERN,
            static fn (array $matches): string => $matches[1] . '=' . self::REDACTED,
            $value,
        ) ?? $value;
        $value = preg_replace(self::IBAN_PATTERN, self::REDACTED, $value) ?? $value;
        $value = preg_replace(self::JWT_PATTERN, self::REDACTED, $value) ?? $value;
        $value = preg_replace(self::AUTHORIZATION_HEADER_PATTERN, '$1: $2 ' . self::REDACTED, $value) ?? $value;
        $value = preg_replace_callback(
            self::OTP_INLINE_PATTERN,
            static fn (array $matches): string => $matches[1] . ': ' . self::REDACTED,
            $value,
        ) ?? $value;

        // Whatever this installation added: an identifier's own format, and the ones that keep part
        // of the match so a log still says what was asked for.
        foreach ($this->declared->declaredPatterns() as $pattern) {
            $value = preg_replace($pattern, self::REDACTED, $value) ?? $value;
        }

        foreach ($this->declared->declaredReplacements() as $replacement) {
            $value = preg_replace($replacement['pattern'], $replacement['replacement'], $value) ?? $value;
        }

        return $value;
    }
}
