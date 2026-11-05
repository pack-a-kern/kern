<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\DataTransferObjects;

/**
 * One kind of notification a package sends to devices, as it declares it (`NotificationTypeRegistry`).
 *
 * The account page draws a switch for each kind from these, so a package adds a kind by declaring it
 * and nothing else: the switch, its label in both languages and its default all come from here.
 */
final readonly class NotificationType
{
    /**
     * @param string      $key        stable name, as stored in a person's choices — never renamed once shipped
     * @param string      $labelKey   translation key of the switch's name
     * @param string      $hintKey    translation key of the line under it, saying what is sent and when
     * @param bool        $defaultOn  whether a person who never touched the switch receives it
     * @param string|null $permission offered only to those holding it, for a kind only they receive
     * @param array<string, string> $hintParameters filled into the hint — what is configured, so it says what is true
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $hintKey,
        public bool $defaultOn = true,
        public ?string $permission = null,
        public array $hintParameters = [],
    ) {
    }
}
