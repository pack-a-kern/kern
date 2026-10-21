<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * A taxpayer's picture, which is the user avatar's pipeline with a different owner on the end of it.
 *
 * What is tested here is therefore not the pipeline — `UserAvatarTest` already covers the weighing,
 * the scanning and the rebuild, and the code doing it is the same object. What is new is who may look
 * and who may change, which is the only thing a second kind of owner actually decides.
 */
beforeEach(function (): void {
    Storage::fake('avatars');

    app()->instance(\Kern\Platform\Uploads\Contracts\UploadScanner::class, new class () implements \Kern\Platform\Uploads\Contracts\UploadScanner {
        public function scanOrReject($stream): void
        {
        }
    });
});

/** A real PNG, so it is an image by the same definition the code uses rather than by its name. */
function subjectAvatarUpload(): UploadedFile
{
    $image = imagecreatetruecolor(400, 400);
    imagefilledrectangle($image, 0, 0, 399, 399, (int) imagecolorallocate($image, 30, 120, 90));

    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());
}

/** Someone on the taxpayer at the given role, so each test can say who it is acting as. */
function subjectMember(TaxSubject $subject, MembershipRole $role): User
{
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => $role,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return $user;
}

it('lets an owner give a taxpayer a picture, and serves it back', function (): void {
    $subject = assessableSubject();
    $owner = subjectOwner();

    $this->actingAs($owner)
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertOk()
        ->assertJsonStructure(['avatar_updated_at']);

    $stored = TaxSubject::query()->findOrFail($subject->id);

    expect($stored->avatarPath())->toBeString();

    $this->actingAs($owner)
        ->get("/api/v1/subjects/{$subject->id}/avatar")
        ->assertOk()
        // Whatever arrived, what is stored is a rebuilt WebP — the normaliser's output, not the upload.
        ->assertHeader('Content-Type', 'image/webp');
});

/*
 * Everyone the taxpayer opens for sees the same card — today its owners, since a role without rules
 * opens nothing (MembershipAuthorizationTest). It is the one thing on this table that is not
 * ciphertext, and the route is what limits it instead.
 */
it('serves the picture to every owner of the taxpayer', function (): void {
    $subject = assessableSubject();

    $this->actingAs(subjectOwner())
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertOk();

    $this->actingAs(subjectMember($subject, MembershipRole::OWNER))
        ->get("/api/v1/subjects/{$subject->id}/avatar")
        ->assertOk();
});

/*
 * The id space must not be walkable. Without this, anybody signed in could ask for pictures until one
 * came back and learn which taxpayers this installation holds.
 */
it('refuses the picture to somebody with no membership', function (): void {
    $subject = assessableSubject();

    $this->actingAs(subjectOwner())
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->get("/api/v1/subjects/{$subject->id}/avatar")
        ->assertForbidden();
});

it('refuses the picture once a membership is revoked', function (): void {
    $subject = assessableSubject();
    $member = subjectMember($subject, MembershipRole::OWNER);

    $this->actingAs(subjectOwner())
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertOk();

    SubjectMembership::query()
        ->where('subject_id', $subject->id)
        ->where('user_id', $member->id)
        ->update(['revoked_at' => now('UTC')]);

    $this->actingAs($member)
        ->get("/api/v1/subjects/{$subject->id}/avatar")
        ->assertForbidden();
});

/*
 * Changing it is the owner's, like changing what a taxpayer is or whether it is in use: the picture is
 * how everyone on the account recognises which taxpayer they are in, so it is not one member's to
 * change on everybody else's screen.
 */
it('refuses a member who is not the owner', function (): void {
    $subject = assessableSubject();
    $member = subjectMember($subject, MembershipRole::VIEWER);

    $this->actingAs($member)
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete("/api/v1/subjects/{$subject->id}/avatar")
        ->assertForbidden();
});

it('takes the picture back off, leaving initials', function (): void {
    $subject = assessableSubject();
    $owner = subjectOwner();

    $this->actingAs($owner)
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertOk();

    $this->actingAs($owner)
        ->delete("/api/v1/subjects/{$subject->id}/avatar")
        ->assertOk()
        ->assertJson(['avatar_updated_at' => null]);

    expect(TaxSubject::query()->findOrFail($subject->id)->avatarPath())->toBeNull();

    $this->actingAs($owner)
        ->get("/api/v1/subjects/{$subject->id}/avatar")
        ->assertNotFound();
});

/* A taxpayer that never had one is a 404, not a placeholder: the screen draws the initials. */
it('answers 404 when there is no picture', function (): void {
    $subject = assessableSubject();

    $this->actingAs(subjectOwner())
        ->get("/api/v1/subjects/{$subject->id}/avatar")
        ->assertNotFound();
});

/*
 * The audit row says what changed, and it has to say `tax_subject` rather than `user` — the two are
 * different things to anybody reading the chain, and this is the one place the distinction is made.
 */
it('records the change against the taxpayer, not against a user', function (): void {
    $subject = assessableSubject();

    $this->actingAs(subjectOwner())
        ->post("/api/v1/subjects/{$subject->id}/avatar", ['avatar' => subjectAvatarUpload()])
        ->assertOk();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'TAX_SUBJECT_AVATAR_SET',
        'resource_type' => 'tax_subject',
        'resource_id' => $subject->id,
    ]);
});
