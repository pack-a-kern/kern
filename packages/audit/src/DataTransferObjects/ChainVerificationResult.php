<?php

declare(strict_types=1);

namespace Kern\Audit\DataTransferObjects;

use Kern\Audit\ChainVerificationFailureReason;

/**
 * The outcome of walking one chain end to end with
 * {@see \Kern\Audit\Services\ChainVerifier::verify()}.
 *
 * `brokenAtSequence` is the one number a human or an incident responder actually needs: not "the
 * chain is broken", but the exact sequence number after which the chain can no longer be trusted.
 */
final readonly class ChainVerificationResult
{
    private function __construct(
        public string $chainKey,
        public bool $ok,
        public int $verifiedCount,
        public ?int $brokenAtSequence,
        public ?ChainVerificationFailureReason $reason,
    ) {
    }

    public static function ok(string $chainKey, int $verifiedCount): self
    {
        return new self($chainKey, true, $verifiedCount, null, null);
    }

    public static function brokenAt(
        string $chainKey,
        int $verifiedCount,
        int $sequence,
        ChainVerificationFailureReason $reason,
    ): self {
        return new self($chainKey, false, $verifiedCount, $sequence, $reason);
    }
}
