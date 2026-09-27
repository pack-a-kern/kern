<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement\Contracts;

use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * Moving an existing wrapped DEK onto a newer KEK version.
 *
 * Deliberately a second interface rather than a method on {@see SubjectDekRepository}. That one is
 * create-only by contract and has to stay so: `save()` is only ever reached with a freshly
 * generated random DEK, from the branch where `find()` found none. If it could overwrite, two
 * callers racing on the same subject would let the second replace a live DEK with different bytes,
 * and every field already encrypted under the first would be unrecoverable. Rotation needs the
 * opposite guarantee — write *only* where a DEK already exists — so it gets its own interface and
 * its own statement, and neither guarantee can be reached through the other's door.
 *
 * Rotating the KEK is not enough on its own. OpenBao retains old key versions, so every DEK wrapped
 * before a rotation keeps decrypting — and therefore keeps depending on the version it was wrapped
 * under. Re-wrapping is what actually retires that version: unwrap under the old one, wrap the same
 * 32 bytes under the new. No field ciphertext is touched, because the DEK's own bytes never change.
 *
 * `docs/handoff/005-crypto-core.md` "How to rotate a key" step 4 describes this by hand; this is
 * that step, in code.
 */
interface DekRewrapRepository
{
    /**
     * How many wrapped DEKs this store holds per KEK version, by the table they live in.
     *
     * The report a rotation is judged by: when every count sits under the current version, nothing
     * depends on an older one any more, which is the precondition for retiring it in the KMS.
     *
     * @return array<string, array<int, int>> table name => (KEK version => rows)
     */
    public function versionCounts(): array;

    /**
     * Up to `$limit` wrapped DEKs not yet on `$version`.
     *
     * No cursor, deliberately: a re-wrapped row is no longer below the version, so calling this
     * again returns the next batch. A run interrupted halfway resumes by being run again, and
     * nothing has to remember where it stopped.
     *
     * @return list<array{id: string, wrapped: WrappedDataKey}>
     */
    public function below(int $version, int $limit): array;

    /**
     * Replace one row's wrapped DEK, and only ever with a newer KEK version.
     *
     * Implementations must express both halves in the statement itself — the row already has a DEK,
     * and its version is lower than the incoming one — so no path through here can touch a row that
     * has none, and a slower concurrent run cannot move a row backwards.
     *
     * @return bool whether the row moved; false when it no longer qualified.
     */
    public function rewrap(string $id, WrappedDataKey $wrapped): bool;
}
