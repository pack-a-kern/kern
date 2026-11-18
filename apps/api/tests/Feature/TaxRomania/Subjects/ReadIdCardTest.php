<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;
use Kern\TaxRomania\Subjects\IdCard\DataTransferObjects\IdCardReadResult;
use Kern\TaxRomania\Subjects\IdCard\Events\IdCardReadChanged;
use Kern\TaxRomania\Subjects\IdCard\Jobs\ReadIdCardJob;
use Kern\TaxRomania\Subjects\IdCard\Services\IdCardReader;
use Kern\TaxRomania\Subjects\IdCard\Services\IdCardScratch;

/** The provider a read talks to, configured as an installation would (ADR 0021), faked below. */
function configureIdCardProvider(): void
{
    config([
        'ai.providers.defaults.enabled' => true,
        'ai.providers.defaults.provider' => 'custom',
        'ai.providers.defaults.base_url' => 'https://ai.test',
        'ai.providers.defaults.model' => 'vision-1',
    ]);
}

/**
 * A model reply in the OpenAI-compatible shape the dialect reads.
 *
 * @param  array<string, mixed>  $json
 */
function idCardReply(array $json): void
{
    Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode($json, JSON_THROW_ON_ERROR)]]]])]);
}

function aCardImage(): UploadedFile
{
    return UploadedFile::fake()->image('card.png', 600, 380);
}

it('will not read a card without the user consenting', function (): void {
    configureIdCardProvider();
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post('/api/v1/subjects/id-card/read', ['file' => aCardImage(), 'consent' => '0'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('refuses to read when no AI provider is configured', function (): void {
    // ai.enabled defaults to false — the capability is simply unavailable (ADR 0005 consequences).
    $this->actingAs(User::factory()->create())
        ->post('/api/v1/subjects/id-card/read', ['file' => aCardImage(), 'consent' => '1'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'ID_CARD_READ_UNAVAILABLE');
});

it('refuses a file that is not an image, whatever it claims to be', function (): void {
    configureIdCardProvider();

    // A text file renamed to look like a photo: the bytes decide, not the name (BLUEPRINT §14.2).
    $notAnImage = UploadedFile::fake()->createWithContent('card.png', 'this is plainly not an image');

    $this->actingAs(User::factory()->create())
        ->post('/api/v1/subjects/id-card/read', ['file' => $notAnImage, 'consent' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'NOT_AN_IMAGE');
});

it('turns away a card larger than the cap, before reading it into memory', function (): void {
    configureIdCardProvider();
    config(['ai.idcard.read.max_image_bytes' => 64]);
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post('/api/v1/subjects/id-card/read', ['file' => aCardImage(), 'consent' => '1'])
        ->assertStatus(413)
        ->assertJsonPath('reason', 'ID_CARD_TOO_LARGE');

    Queue::assertNothingPushed();
});

it('refuses a card that fails the virus scan, and queues nothing', function (): void {
    configureIdCardProvider();
    Queue::fake();

    // The same scan every kept upload gets — forced to reject here, as an infected file would.
    app()->instance(UploadScanner::class, new class () implements UploadScanner {
        public function scanOrReject($stream): void
        {
            throw UploadRejectedException::infected();
        }
    });

    $this->actingAs(User::factory()->create())
        ->post('/api/v1/subjects/id-card/read', ['file' => aCardImage(), 'consent' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'ID_CARD_UNSAFE');

    Queue::assertNothingPushed();
});

it('answers at once and leaves the reading to a job', function (): void {
    configureIdCardProvider();
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/api/v1/subjects/id-card/read', ['file' => aCardImage(), 'consent' => '1'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'reading');

    $token = $response->json('token');
    expect($token)->toBeString()->not->toBe('');

    Queue::assertPushed(ReadIdCardJob::class, static fn (ReadIdCardJob $job): bool => $job->token === $token && $job->userId === $user->id);
});

it('keeps the uploaded card encrypted, never in the clear', function (): void {
    $scratch = app(IdCardScratch::class);
    $plaintext = 'the raw bytes of a card';
    $scratch->putImage('tokenc', $plaintext, 'image/png');

    /** @var array{mime: string, data: string} $stored */
    $stored = Cache::get('idcard:img:tokenc');

    // What sits in the store is ciphertext — the plaintext never appears in it.
    expect($stored['data'])->not->toContain($plaintext);
    // And it round-trips, once: a second pull finds nothing.
    expect($scratch->pullImage('tokenc'))->toBe(['mime' => 'image/png', 'bytes' => $plaintext]);
    expect($scratch->pullImage('tokenc'))->toBeNull();
});

it('hands a reading to its owner exactly once and to no one else', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    app(IdCardScratch::class)->putResult('tokres', $owner->id, ['status' => 'ready', 'reading' => ['cnp' => '1234567890123']]);

    // Someone else's guess reveals nothing and does not consume the one read.
    $this->actingAs($other)->get('/api/v1/subjects/id-card/read/tokres')->assertStatus(404);

    // The owner gets it once…
    $this->actingAs($owner)->get('/api/v1/subjects/id-card/read/tokres')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('reading.cnp', '1234567890123');

    // …and never again.
    $this->actingAs($owner)->get('/api/v1/subjects/id-card/read/tokres')->assertStatus(404);
});

it('reads the fields and the face box, clamping a box that runs off the card', function (): void {
    configureIdCardProvider();
    idCardReply([
        'cnp' => '1960101223344',
        'last_name' => 'POPESCU',
        'first_name' => 'ION',
        'address' => 'Str. Exemplu 1',
        'series' => 'XZ',
        'number' => '123456',
        'valid_until' => '2031-01-01',
        // Starts near the right edge and asks for more width than is left: must be clamped.
        'face_box' => ['x' => 0.8, 'y' => 0.1, 'w' => 0.5, 'h' => 0.3],
    ]);

    $result = app(IdCardReader::class)->read('bytes', 'image/jpeg');

    expect($result->succeeded())->toBeTrue();
    expect($result->reading?->cnp)->toBe('1960101223344');
    expect($result->reading?->lastName)->toBe('POPESCU');
    expect($result->reading?->faceBox?->x)->toBe(0.8);
    // 0.8 + 0.5 would exceed 1; the width is clamped to what is left.
    expect($result->reading?->faceBox?->w)->toEqualWithDelta(0.2, 1e-9);
});

it('keeps a CNP the model returned as a number, not only as a string', function (): void {
    configureIdCardProvider();
    // A provider that does not honour the schema returns the all-digit CNP as a JSON number.
    idCardReply(['cnp' => 1960101223344, 'last_name' => 'POPESCU', 'face_box' => null]);

    $result = app(IdCardReader::class)->read('bytes', 'image/jpeg');

    expect($result->succeeded())->toBeTrue();
    expect($result->reading?->cnp)->toBe('1960101223344');
});

it('sends the system prompt and the instruction, never the image alone', function (): void {
    // The regression this guards: an empty ID_CARD_PROMPT_* override (as a fresh .env carries) must
    // not reach the model as an empty prompt — the model would then get the image with no
    // instructions and read a name but no structured CNP. The prompts must always be present.
    configureIdCardProvider();
    idCardReply(['cnp' => '1960101223344', 'face_box' => null]);

    app(IdCardReader::class)->read('bytes', 'image/jpeg');

    Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        $data = $request->data();
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];

        $system = null;
        $instruction = null;
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            if (($message['role'] ?? null) === 'system' && is_string($message['content'] ?? null)) {
                $system = $message['content'];
            }
            if (($message['role'] ?? null) === 'user') {
                $content = $message['content'] ?? null;
                if (is_string($content)) {
                    $instruction = $content;
                } elseif (is_array($content)) {
                    foreach ($content as $part) {
                        if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                            $instruction = $part['text'];
                        }
                    }
                }
            }
        }

        return $system !== null && $system !== '' && str_contains($system, 'Romanian identity card')
            && $instruction !== null && $instruction !== '' && str_contains($instruction, 'return JSON');
    });
});

it('says it could not read the card when the provider refuses', function (): void {
    configureIdCardProvider();
    Http::fake(['ai.test/*' => Http::response('', 502)]);

    $result = app(IdCardReader::class)->read('bytes', 'image/jpeg');

    expect($result->succeeded())->toBeFalse();
    expect($result->failure)->toBe(IdCardReadResult::REFUSED);
});

it('reads from the scratch store and nudges the browser when the job runs', function (): void {
    configureIdCardProvider();
    idCardReply(['cnp' => '1960101223344', 'face_box' => null]);
    Event::fake([IdCardReadChanged::class]);

    $user = User::factory()->create();
    $scratch = app(IdCardScratch::class);
    $scratch->putImage('tokjob', 'card-bytes', 'image/png');

    (new ReadIdCardJob('tokjob', $user->id))->handle(app(IdCardReader::class), $scratch);

    // The image is consumed…
    expect($scratch->pullImage('tokjob'))->toBeNull();
    // …a result is waiting for the owner…
    expect($scratch->pullResult('tokjob', $user->id))->toMatchArray(['status' => 'ready']);
    // …and the browser was nudged, with the token and status only.
    Event::assertDispatched(IdCardReadChanged::class, static fn (IdCardReadChanged $event): bool => $event->token === 'tokjob' && $event->userId === $user->id && $event->status === 'ready');
});
