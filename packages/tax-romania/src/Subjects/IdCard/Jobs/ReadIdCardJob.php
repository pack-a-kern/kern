<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\TaxRomania\Subjects\IdCard\DataTransferObjects\IdCardReadResult;
use Kern\TaxRomania\Subjects\IdCard\Events\IdCardReadChanged;
use Kern\TaxRomania\Subjects\IdCard\Services\IdCardReader;
use Kern\TaxRomania\Subjects\IdCard\Services\IdCardScratch;
use Throwable;

/**
 * Reads one identity card out of the scratch store and leaves the result there for the browser,
 * then says over the socket that it is done (ADR 0028).
 *
 * The job carries **only a token**, never the image — the card is sealed in the scratch store and
 * this pulls-and-deletes it, so it exists in plaintext only for this handler's lifetime and never in
 * the queue. A vision read can take a minute, longer than a request may be held, which is why it is
 * here and the answer comes over a socket nudge rather than a held response.
 *
 * One try: the image is consumed on the first attempt, so a retry would find nothing to read. A
 * failure of any kind becomes a failed result the browser can act on — "type it by hand" — never a
 * silently dropped read.
 */
final class ReadIdCardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $token,
        public readonly string $userId,
    ) {
        // A margin over the read's own ceiling so the worker is not killed mid-call.
        $this->timeout = config()->integer('ai.idcard.read.timeout_seconds') + 30;
        $this->onQueue(config()->string('ai.idcard.read.queue'));
    }

    public function handle(IdCardReader $reader, IdCardScratch $scratch): void
    {
        $image = $scratch->pullImage($this->token);

        $result = $image === null
            ? IdCardReadResult::failed(IdCardReadResult::EXPIRED)
            : $reader->read($image['bytes'], $image['mime']);

        $scratch->putResult($this->token, $this->userId, $result->toArray());

        IdCardReadChanged::dispatch($this->userId, $this->token, $result->succeeded() ? 'ready' : 'failed');
    }

    public function failed(?Throwable $exception): void
    {
        // The image was already pulled, so there is nothing left to read; leave a failure for the
        // browser so a screen waiting on the nudge is not left waiting forever.
        app(IdCardScratch::class)->putResult(
            $this->token,
            $this->userId,
            IdCardReadResult::failed(IdCardReadResult::REFUSED)->toArray(),
        );

        IdCardReadChanged::dispatch($this->userId, $this->token, 'failed');
    }
}
