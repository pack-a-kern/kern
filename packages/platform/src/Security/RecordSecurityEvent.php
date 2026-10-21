<?php

declare(strict_types=1);

namespace Kern\Platform\Security;

use InvalidArgumentException;

/**
 * Writes one row to the security log — the one way it is written.
 *
 * The log is read by people watching for attacks, kept unencrypted and longer than a session, so
 * it must never hold anything about a person beyond the id of the row it concerns (`BLUEPRINT.md`
 * §23). That used to be a sentence in a docblock, kept by four hand-written inserts. Here it is a
 * check: the context is a short list of codes — `method => password`, `reason => role_without_rules`
 * — and anything else (an address, a name, a sentence, a nested value) is refused before it is
 * written.
 */
final readonly class RecordSecurityEvent
{
    /** A code: lower case, digits and underscores, the shape of every value this log has needed. */
    private const string CODE = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param  array<string, mixed>  $context  codes only; anything else is refused
     */
    public function __invoke(SecurityEventType $type, ?string $userId, ?string $subjectId, array $context): void
    {
        foreach ($context as $key => $value) {
            if (! is_string($value) || preg_match(self::CODE, $key) !== 1 || preg_match(self::CODE, $value) !== 1) {
                throw new InvalidArgumentException(sprintf('Security event context holds codes only; "%s" is not one.', $key));
            }
        }

        SecurityEvent::create([
            'event_type' => $type,
            'user_id' => $userId,
            'subject_id' => $subjectId,
            'context_json' => $context,
            'occurred_at' => now('UTC'),
        ]);
    }
}
