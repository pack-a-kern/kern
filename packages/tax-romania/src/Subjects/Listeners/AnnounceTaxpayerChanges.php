<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Listeners;

use Kern\TaxRomania\Subjects\Events\TaxpayersChanged;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Tells the people a taxpayer belongs to that their list of taxpayers changed ({@see TaxpayersChanged}).
 *
 * Watches the two models the list is made of, rather than each action that writes them: an action
 * added later — or an import that touches a taxpayer — is covered without remembering to announce it.
 * Only what the list shows counts: a taxpayer saved for its display currency, or its data key
 * rewrapped, changes nothing anyone sees in the switcher, and is not announced.
 */
final class AnnounceTaxpayerChanges
{
    /** What the switcher draws a taxpayer with. */
    private const array SHOWN = ['display_name_ciphertext', 'type', 'status', 'avatar_updated_at'];

    /**
     * Changed, not created: a new taxpayer has no members yet when it is saved — its first membership,
     * a moment later, is what puts it on someone's list, and that is announced on its own. Nor is
     * `wasRecentlyCreated` a way to tell: it stays true on the instance for every save after, and
     * announced a change of display currency.
     */
    public static function subjectSaved(TaxSubject $subject): void
    {
        if ($subject->wasChanged(self::SHOWN)) {
            self::announce(self::membersOf($subject->id));
        }
    }

    public static function subjectDeleted(TaxSubject $subject): void
    {
        self::announce(self::membersOf($subject->id));
    }

    /** Gaining, losing or changing access to a taxpayer changes that one person's list. */
    public static function membershipChanged(SubjectMembership $membership): void
    {
        self::announce([$membership->user_id]);
    }

    /** @return list<string> */
    private static function membersOf(string $subjectId): array
    {
        /** @var list<string> */
        return SubjectMembership::query()->active()->where('subject_id', $subjectId)->distinct()->pluck('user_id')->all();
    }

    /** @param list<string> $userIds */
    private static function announce(array $userIds): void
    {
        if ($userIds !== []) {
            TaxpayersChanged::dispatch($userIds);
        }
    }
}
