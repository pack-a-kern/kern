<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\TaxpayersChangedTest;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Events\TaxpayersChanged;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Who hears that a taxpayer changed: everyone it belongs to, for what the switcher shows, and nobody
 * for what it does not.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function member(TaxSubject $subject, ?User $user = null): User
{
    $user ??= User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return $user;
}

it('tells every member when what the switcher shows changes', function (): void {
    $subject = TaxSubject::factory()->create();
    $owner = member($subject);
    $accountant = member($subject);
    Event::fake([TaxpayersChanged::class]);

    $subject->saveAvatar('avatars/x.webp', now());

    Event::assertDispatched(TaxpayersChanged::class, static function (TaxpayersChanged $event) use ($owner, $accountant): bool {
        $heard = $event->userIds;
        sort($heard);
        $expected = [$owner->id, $accountant->id];
        sort($expected);

        // On each one's own channel, and saying nothing of what changed.
        return $heard === $expected
            && array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()) === array_map(static fn (string $id): string => "private-users.{$id}", $event->userIds)
            && $event->broadcastWith() === [];
    });
});

it('tells the one person whose access changed', function (): void {
    $subject = TaxSubject::factory()->create();
    member($subject);
    Event::fake([TaxpayersChanged::class]);

    $newcomer = member($subject);

    Event::assertDispatched(TaxpayersChanged::class, static fn (TaxpayersChanged $event): bool => $event->userIds === [$newcomer->id]);
});

it('says nothing for a change the switcher does not show', function (): void {
    $subject = TaxSubject::factory()->create();
    member($subject);
    Event::fake([TaxpayersChanged::class]);

    $subject->forceFill(['display_currency' => 'EUR'])->save();

    Event::assertNotDispatched(TaxpayersChanged::class);

    $subject->forceFill(['status' => SubjectStatus::SUSPENDED])->save();

    Event::assertDispatchedTimes(TaxpayersChanged::class, 1);
});
