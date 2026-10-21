<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;
use Kern\Platform\Uploads\RefusingUploadScanner;

beforeEach(function (): void {
    Storage::fake('avatars');

    /*
     * A scanner that passes, so these tests are about the HTTP layer rather than about clamd. The one
     * test that cares about a refusal binds its own. The default binding is deliberately the opposite —
     * see the test at the bottom of this file.
     */
    app()->instance(UploadScanner::class, new class () implements UploadScanner {
        public function scanOrReject($stream): void
        {
        }
    });
});

/** `getKey()` is `mixed`; every route here needs the id as a string, so it is narrowed once. */
function avatarUserId(User $user): string
{
    $key = $user->getKey();

    return is_scalar($key) ? (string) $key : '';
}

/** A real PNG of the given size — GD writes it, so it is an image by the same definition the code uses. */
function avatarUpload(int $width = 400, int $height = 400): UploadedFile
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 30, 120, 90));

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent('face.png', $bytes);
}

it('stores a square webp and reports when it was set', function (): void {
    $admin = settingsAdminUser();

    $response = $this->actingAs($admin)
        ->post('/api/v1/me/avatar', ['avatar' => avatarUpload(900, 300)])
        ->assertOk();

    expect($response->json('avatar_updated_at'))->toBeString();

    $admin->refresh();

    $path = (string) $admin->avatar_path;

    expect($path)->toBe(avatarUserId($admin) . '.webp')
        ->and($admin->avatar_updated_at)->not->toBeNull();

    $size = getimagesizefromstring((string) Storage::disk('avatars')->get($path));

    if ($size === false) {
        throw new RuntimeException('the stored avatar is not a readable image');
    }

    expect($size[0])->toBe(256)->and($size[1])->toBe(256)->and($size[2])->toBe(IMAGETYPE_WEBP);
});

it('serves the picture to the person it belongs to', function (): void {
    $user = ordinaryUser();
    $admin = settingsAdminUser();

    $this->actingAs($user)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()]);

    $this->actingAs($user)
        ->get('/api/v1/users/' . avatarUserId($user) . '/avatar')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses to show one account its picture of another without users.view', function (): void {
    $admin = settingsAdminUser();
    $stranger = ordinaryUser();

    $this->actingAs($admin)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()]);

    $this->actingAs($stranger)
        ->get('/api/v1/users/' . avatarUserId($admin) . '/avatar')
        ->assertForbidden();
});

it('answers 404 for an account that has no picture, so the screen draws initials', function (): void {
    $admin = settingsAdminUser();

    $this->actingAs($admin)->get('/api/v1/users/' . avatarUserId($admin) . '/avatar')->assertNotFound();
});

it('answers 404 when the row points at a file the disk no longer has', function (): void {
    $admin = settingsAdminUser();

    $this->actingAs($admin)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()]);

    Storage::disk('avatars')->delete($admin->refresh()->avatar_path ?? '');

    $this->actingAs($admin)->get('/api/v1/users/' . avatarUserId($admin) . '/avatar')->assertNotFound();
});

it('puts the initials back, and takes the file with it', function (): void {
    $admin = settingsAdminUser();

    $this->actingAs($admin)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()]);

    $path = (string) $admin->refresh()->avatar_path;

    expect(Storage::disk('avatars')->exists($path))->toBeTrue();

    $this->actingAs($admin)->delete('/api/v1/me/avatar')->assertOk();

    $admin->refresh();

    expect($admin->avatar_path)->toBeNull()
        ->and($admin->avatar_updated_at)->toBeNull()
        ->and(Storage::disk('avatars')->exists($path))->toBeFalse();
});

it('refuses a file that is not an image, naming the reason', function (): void {
    $admin = settingsAdminUser();

    $this->actingAs($admin)
        ->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('shell.png', 'GIF89a<?php echo 1; ?>'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_an_image');

    expect($admin->refresh()->avatar_path)->toBeNull();
});

it('keeps nothing when the scanner refuses the bytes', function (): void {
    $admin = settingsAdminUser();

    app()->instance(UploadScanner::class, new class () implements UploadScanner {
        public function scanOrReject($stream): void
        {
            throw UploadRejectedException::infected();
        }
    });

    $this->actingAs($admin)
        ->post('/api/v1/me/avatar', ['avatar' => avatarUpload()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'infected');

    expect($admin->refresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('avatars')->allFiles())->toBe([]);
});

/*
 * The point of moving uploads off the administrative route: an ordinary account, holding no
 * permission at all, can give itself a picture. There is no id in `v1/me/avatar` to aim elsewhere.
 */
it('lets any signed-in account set its own picture, with no permission', function (): void {
    $user = ordinaryUser();

    $this->actingAs($user)
        ->post('/api/v1/me/avatar', ['avatar' => avatarUpload()])
        ->assertOk();

    expect($user->refresh()->avatar_path)->toBe(avatarUserId($user) . '.webp');
});

/*
 * Choosing somebody else's face is nobody's job, so the route that allowed it is gone rather than
 * merely guarded. A POST there must not quietly fall through to some other handler.
 */
it('no longer offers an administrative route for uploading somebody else\'s picture', function (): void {
    $admin = settingsAdminUser();
    $user = ordinaryUser();

    $this->actingAs($admin)
        ->post('/api/v1/admin/users/' . avatarUserId($user) . '/avatar', ['avatar' => avatarUpload()])
        ->assertStatus(405);
});

/*
 * Removal stays administrative, because taking down something that should not be on the screen is a
 * real duty — and it is still refused to an account without `users.manage`.
 */
it('lets an administrator take somebody else\'s picture down', function (): void {
    $admin = settingsAdminUser();
    $user = ordinaryUser();

    $this->actingAs($user)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()])->assertOk();

    $this->actingAs($admin)
        ->delete('/api/v1/admin/users/' . avatarUserId($user) . '/avatar')
        ->assertOk();

    expect($user->refresh()->avatar_path)->toBeNull();
});

it('refuses an ordinary account taking somebody else\'s picture down', function (): void {
    $user = ordinaryUser();
    $other = settingsAdminUser();

    $this->actingAs($other)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()])->assertOk();

    $this->actingAs($user)
        ->delete('/api/v1/admin/users/' . avatarUserId($other) . '/avatar')
        ->assertForbidden();

    expect($other->refresh()->avatar_path)->not->toBeNull();
});

it('reports the timestamp in the directory, so a list knows which rows have a face', function (): void {
    $admin = settingsAdminUser();

    $this->actingAs($admin)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()]);

    /** @var list<array<string, mixed>> $users */
    $users = $this->actingAs($admin)->getJson('/api/v1/admin/users')->json('users');
    $row = collect($users)->firstWhere('id', avatarUserId($admin));

    expect($row)->toBeArray();
    expect($row['avatar_updated_at'] ?? null)->toBeString();
});

it('records both the setting and the removal in the audit chain', function (): void {
    $admin = settingsAdminUser();
    $id = avatarUserId($admin);

    $this->actingAs($admin)->post('/api/v1/me/avatar', ['avatar' => avatarUpload()]);
    $this->actingAs($admin)->delete('/api/v1/me/avatar');

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'USER_AVATAR_SET',
        'resource_type' => 'user',
        'resource_id' => $id,
        'actor_id' => $id,
    ]);
    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'USER_AVATAR_REMOVED',
        'resource_id' => $id,
    ]);
});

/**
 * The binding this installation falls back to, and why it is the refusing one.
 *
 * Both providers are auto-discovered and `kern/documents` registers first, so the platform's default
 * must not overwrite the real scanner — it uses `bindIf` for exactly that reason. This asserts the
 * fallback is still the safe one, which is the half that matters if `kern/documents` is ever dropped.
 */
it('refuses everything when no scanner is configured', function (): void {
    $stream = fopen('php://temp', 'r+b');

    if ($stream === false) {
        throw new RuntimeException('no temporary stream could be opened');
    }

    try {
        expect(fn () => (new RefusingUploadScanner())->scanOrReject($stream))
            ->toThrow(UploadRejectedException::class, 'no scanner is configured for this installation');
    } finally {
        fclose($stream);
    }
});
