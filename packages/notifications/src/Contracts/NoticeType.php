<?php

declare(strict_types=1);

namespace Kern\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\DataTransferObjects\Message;

/**
 * One kind of notice an application sends — everything about it, in one class.
 *
 * The bell, the device notification and the person's switch for the kind are all drawn from here,
 * so adding a kind is writing one of these and registering it (`NoticeTypes::register()`); nothing
 * else in the application or its user interface names it.
 *
 * Resolved from the container when it is used, so it may take what it needs in its constructor —
 * a repository to look up what `$data` points at, for instance.
 */
interface NoticeType
{
    /**
     * The kind's stable name: stored with every notice and every person's choice, so never renamed
     * once shipped.
     */
    public static function key(): string;

    /** The switch's name, in `$locale`. */
    public function label(string $locale): string;

    /** The line under the switch, in `$locale`: what is sent, and when. */
    public function hint(string $locale): string;

    /**
     * What each of these notices says, in `$locale`, written from what was stored when it was sent.
     *
     * Called each time notices are shown — the bell's unread ones together, one on a device — and kept
     * nowhere, so it may say what `$data` must not hold: an amount, a name. Given them all at once, so
     * what it looks up it looks up once. It is also where to ask whether `$notifiable` may still see
     * them: a notice is not a way to keep reading what someone has since lost access to. One left out
     * of the answer — not theirs any more, or about something gone — is not shown.
     *
     * @param  array<string, array<string, mixed>>  $notices  each notice's stored data, by notice id
     * @return array<string, Message> by notice id
     */
    public function render(array $notices, Model $notifiable, string $locale): array;
}
