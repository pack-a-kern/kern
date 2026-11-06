<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Banks\BankLogosTest;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Services\StoredSettingsOverlay;
use Kern\TaxRomania\Banks\Logos\BankLogos;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Bank logos from the logo folder: which files count, and every way a file outside it must not.
 *
 * Each test gets its own folder under the system's temporary directory, so nothing here reads or
 * writes the installation's real `storage/`.
 */
const PNG = "\x89PNG\r\n\x1a\n" . 'png-body';

const WEBP = 'RIFF' . "\x10\x00\x00\x00" . 'WEBPVP8 webp-body';

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>';

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);

    $this->root = sys_get_temp_dir() . '/kern-bank-logos-' . bin2hex(random_bytes(6));
    $this->outside = $this->root . '-outside';
    mkdir($this->root . '/RO', 0o755, true);
    mkdir($this->outside, 0o755, true);

    config(['banks.logos.path' => $this->root]);
    Cache::forget(BankLogos::CACHE_KEY);
});

afterEach(function (): void {
    foreach ([$this->root, $this->outside] as $directory) {
        removeTree($directory);
    }
});

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        removeTree($path . '/' . $entry);
    }

    rmdir($path);
}

function logos(): BankLogos
{
    return app(BankLogos::class);
}

/** @return array{0: User, 1: string} a person who can see the bank list, and their taxpayer */
function personWithBanks(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject->id];
}

it('finds a bank’s logo in its own country’s folder, by its BIC’s first eight characters or its LEI', function (): void {
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);
    file_put_contents($this->root . '/RO/2138008AVF4W7FMW8W87.png', PNG);

    expect(logos()->for('RO', 'BTRLRO22XXX', null, null)?->toArray())->toBe(['file' => 'RO/BTRLRO22.svg', 'mime_type' => 'image/svg+xml', 'fingerprint' => substr(sha1(SVG), 0, 12)])
        ->and(logos()->for('RO', null, '2138008AVF4W7FMW8W87', null)?->mimeType)->toBe('image/png')
        ->and(logos()->for('RO', 'CECEROBUXXX', null, null))->toBeNull();
});

it('names a branch’s file after its parent’s LEI, and only a bank with neither a BIC nor an LEI', function (): void {
    // A branch is no legal entity: Nexent's Bucharest branch has no LEI or BIC of its own.
    file_put_contents($this->root . '/RO/P2HWO703XN8TPXCGU747.svg', SVG);

    expect(logos()->for('RO', null, null, 'P2HWO703XN8TPXCGU747')?->file)->toBe('RO/P2HWO703XN8TPXCGU747.svg')
        // A subsidiary has a logo of its own: its parent's is not borrowed.
        ->and(logos()->for('RO', 'RNCBROBUXXX', null, 'P2HWO703XN8TPXCGU747'))->toBeNull()
        ->and(\Kern\TaxRomania\Banks\Logos\BankLogos::fileFor('RO', null, null, 'P2HWO703XN8TPXCGU747'))->toBe('RO/P2HWO703XN8TPXCGU747')
        ->and(\Kern\TaxRomania\Banks\Logos\BankLogos::fileFor('RO', null, null, null))->toBeNull();
});

it('looks only in the bank’s own country’s folder', function (): void {
    mkdir($this->root . '/BG');
    file_put_contents($this->root . '/BG/BTRLRO22.svg', SVG);
    file_put_contents($this->root . '/BTRLRO22.svg', SVG);

    expect(logos()->for('RO', 'BTRLRO22XXX', null, null))->toBeNull()
        ->and(logos()->for('BG', 'BTRLRO22XXX', null, null)?->file)->toBe('BG/BTRLRO22.svg');
});

it('prefers SVG, then WebP, then PNG, when one bank has several', function (): void {
    file_put_contents($this->root . '/RO/BTRLRO22.png', PNG);
    file_put_contents($this->root . '/RO/BTRLRO22.webp', WEBP);
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);

    expect(logos()->for('RO', 'BTRLRO22XXX', null, null)?->file)->toBe('RO/BTRLRO22.svg');
});

it('ignores a file that is not a logo by its name, its content, its size or its place', function (): void {
    file_put_contents($this->root . '/RO/btrlro22.svg', SVG);              // lower case: not a BIC
    file_put_contents($this->root . '/RO/BTRL.svg', SVG);                  // too short
    file_put_contents($this->root . '/RO/RNCBROBU.png', SVG);              // an SVG called PNG
    file_put_contents($this->root . '/RO/WBANRO22.svg', '<html><script>alert(1)</script></html>');
    file_put_contents($this->root . '/RO/BRDEROBU.webp', WEBP . str_repeat('x', 200 * 1024));
    file_put_contents($this->root . '/RO/CECEROBU.gif', 'GIF89a');
    file_put_contents($this->root . '/RO/.INGBROBU.svg', SVG);             // hidden
    mkdir($this->root . '/ro');
    file_put_contents($this->root . '/ro/LIBRROBU.svg', SVG);              // not a country code
    mkdir($this->root . '/RO/deeper');
    file_put_contents($this->root . '/RO/deeper/BACXROBU.svg', SVG);       // too deep

    expect(logos()->refresh())->toBe([]);
});

it('follows no link — to a file, or to a country folder — out of the folder', function (): void {
    file_put_contents($this->outside . '/secret.svg', SVG);
    symlink($this->outside . '/secret.svg', $this->root . '/RO/BTRLRO22.svg');
    mkdir($this->outside . '/BG');
    file_put_contents($this->outside . '/BG/UNCRBGSF.svg', SVG);
    symlink($this->outside . '/BG', $this->root . '/BG');

    expect(logos()->refresh())->toBe([]);
});

it('refuses a file swapped for a link after the folder was read', function (): void {
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);
    $logo = logos()->for('RO', 'BTRLRO22XXX', null, null);
    expect($logo)->not->toBeNull();
    assert($logo !== null);

    unlink($this->root . '/RO/BTRLRO22.svg');
    file_put_contents($this->outside . '/secret.svg', SVG);
    symlink($this->outside . '/secret.svg', $this->root . '/RO/BTRLRO22.svg');

    expect(logos()->contents($logo))->toBeNull();
});

it('has no logos, and says so, while the folder does not exist', function (): void {
    config(['banks.logos.path' => $this->root . '/not-there']);

    expect(logos()->available())->toBeFalse()
        ->and(logos()->refresh())->toBe([]);
});

it('reads a newly configured folder without a refresh', function (): void {
    mkdir($this->outside . '/RO');
    file_put_contents($this->outside . '/RO/BTRLRO22.svg', SVG);
    expect(logos()->for('RO', 'BTRLRO22XXX', null, null))->toBeNull();

    config(['banks.logos.path' => $this->outside]);

    expect(logos()->for('RO', 'BTRLRO22XXX', null, null)?->file)->toBe('RO/BTRLRO22.svg');
});

it('serves a logo so that an SVG can only be drawn, and caches it for good', function (): void {
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);
    $bank = BankFactory::new()->createOne(['bic' => 'BTRLRO22XXX']);

    $response = $this->actingAs(User::factory()->create())
        ->get("/api/v1/banks/{$bank->id}/logo")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('ETag', '"' . substr(sha1(SVG), 0, 12) . '"');

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain('sandbox')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('private')
        ->and($response->getContent())->toBe(SVG);
});

it('answers 404 for a bank without a logo, an unknown bank, or a path in the address', function (): void {
    $without = BankFactory::new()->createOne(['bic' => 'CECEROBUXXX']);
    $user = User::factory()->create();

    $this->actingAs($user)->get("/api/v1/banks/{$without->id}/logo")->assertNotFound();
    $this->actingAs($user)->get('/api/v1/banks/01a0a583-8bcc-71dd-b9aa-b304c7bfdc57/logo')->assertNotFound();
    $this->actingAs($user)->get('/api/v1/banks/..%2F..%2Fetc%2Fpasswd/logo')->assertNotFound();
});

it('serves logos to signed-in people only', function (): void {
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);
    $bank = BankFactory::new()->createOne(['bic' => 'BTRLRO22XXX']);

    $this->getJson("/api/v1/banks/{$bank->id}/logo")->assertUnauthorized();
});

it('gives each bank in the list its logo’s address, versioned by the file, or null', function (): void {
    [$user, $subjectId] = personWithBanks();
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);
    $bt = BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX']);
    BankFactory::new()->createOne(['name' => 'CEC Bank', 'bic' => 'CECEROBUXXX']);

    $banks = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/banks")->assertOk()->json('banks');
    assert(is_array($banks));
    $before = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/banks")->json('banks.0.logo_url');

    expect(array_column($banks, 'logo_url', 'name'))->toBe([
        'Banca Transilvania' => "/api/v1/banks/{$bt->id}/logo?v=" . substr(sha1(SVG), 0, 12),
        'CEC Bank' => null,
    ]);

    // A replaced file is a new address, once the folder is read again.
    file_put_contents($this->root . '/RO/BTRLRO22.svg', str_replace('width="1"', 'width="2"', SVG));
    logos()->refresh();

    $after = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/banks")->json('banks.0.logo_url');
    expect($after)->not->toBe($before);
});

it('describes the folder to the settings screen, and reads it again on request', function (): void {
    $admin = settingsAdminUser();
    BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX', 'ecb_code' => 'RORBPJR12019']);
    $cec = BankFactory::new()->createOne(['name' => 'CEC Bank', 'bic' => 'CECEROBUXXX', 'ecb_code' => 'RORBPJR40046']);
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);

    $this->actingAs($admin)->getJson('/api/v1/settings/banks/logos')
        ->assertOk()
        ->assertJsonPath('path', $this->root)
        ->assertJsonPath('exists', true)
        ->assertJsonPath('banks', 2)
        ->assertJsonPath('with_logo', 1)
        ->assertJsonPath('missing', [['id' => $cec->id, 'name' => 'CEC Bank', 'file' => 'RO/CECEROBU.*']]);

    file_put_contents($this->root . '/RO/CECEROBU.webp', WEBP);

    $this->actingAs($admin)->postJson('/api/v1/settings/banks/logos/refresh')
        ->assertOk()
        ->assertJsonPath('with_logo', 2)
        ->assertJsonPath('missing', []);

    expect(Bank::query()->count())->toBe(2);
});

it('shows the default folder relative to the application', function (): void {
    config(['banks.logos.path' => storage_path('app/private/bank-logos')]);

    $this->actingAs(settingsAdminUser())->getJson('/api/v1/settings/banks/logos')
        ->assertOk()
        ->assertJsonPath('path', 'storage/app/private/bank-logos');
});

it('keeps the folder’s description and its refresh to administrators', function (): void {
    $user = User::factory()->create();

    $this->getJson('/api/v1/settings/banks/logos')->assertUnauthorized();
    $this->actingAs($user)->getJson('/api/v1/settings/banks/logos')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/settings/banks/logos/refresh')->assertForbidden();
});

it('holds a country code to two letters on one line, whatever reaches it', function (): void {
    // A request trims the newline away before validation sees it; a hand-edited row does not, and
    // `$` alone would have let one through.
    expect(SettingType::isCountryCode("RO\n"))->toBeFalse()
        ->and(SettingType::isCountryCode('RO'))->toBeTrue();
});

it('takes the countries as two-letter codes, and refuses anything else', function (mixed $value, ?array $stored): void {
    $admin = settingsAdminUser();

    $response = $this->actingAs($admin)->putJson('/api/v1/settings/group/banks', ['settings' => ['banks.countries' => $value]]);

    if ($stored !== null) {
        $response->assertOk();
        StoredSettingsOverlay::forget();
        app(StoredSettingsOverlay::class)->apply();
        expect(config('banks.countries'))->toBe($stored);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors(['settings.banks.countries']);
    }
})->with([
    'one' => ['RO', ['RO']],
    'typed loosely' => [' ro, bg ,RO', ['RO', 'BG']],
    'as a list' => [['RO', 'BG'], ['RO', 'BG']],
    'a name' => ['Romania', null],
    'an unknown code' => ['XX', null],
    'ICU’s unknown region' => ['ZZ', null],
    'none' => [',', null],
]);

it('reads the index from the cache once for a list of banks, and again after a refresh', function (): void {
    file_put_contents($this->root . '/RO/BTRLRO22.svg', SVG);
    $logos = logos();
    $logos->refresh();

    $reads = 0;
    Event::listen(CacheHit::class, function (CacheHit $event) use (&$reads): void {
        $reads += $event->key === BankLogos::CACHE_KEY ? 1 : 0;
    });

    $logos = logos();

    foreach (range(1, 20) as $ignored) {
        $logos->for('RO', 'BTRLRO22XXX', null, null);
    }

    file_put_contents($this->root . '/RO/CECEROBU.png', PNG);

    expect($reads)->toBe(1)
        ->and($logos->refresh())->toHaveKey('RO/CECEROBU')
        ->and($logos->for('RO', 'CECEROBUXXX', null, null)?->mimeType)->toBe('image/png');
});
