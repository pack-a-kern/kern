<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Identity\Enums\VerificationChannel;

/**
 * One outstanding verification code — see this table's migration for why the code is hashed and why
 * the address it was sent to is stored as a blind index.
 *
 * No `Casts\Encrypted` and therefore no DEK: every column here is a hash, a timestamp or a count.
 * Nothing on this row has to be read back as text, which is what makes it cheap to keep after it has
 * been used — and the record that a code was sent, and guessed at, is worth keeping.
 *
 * @property string $id
 * @property string $user_id
 * @property VerificationChannel $channel
 * @property string $destination_blind_index
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property int $attempts
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class UserVerificationChallenge extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'channel',
        'destination_blind_index',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'channel' => VerificationChannel::class,
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * The one a submission is checked against: not yet used, not yet expired, newest first.
     *
     * Newest rather than "the only one", because asking for another code before the first expires is
     * ordinary — a person who did not receive it asks again — and both remain valid until one is used
     * or time runs out. Answering only the newest would silently invalidate a code that is still in
     * somebody's inbox; answering the newest *first* means the code they most likely have is tried
     * first, and the older one still works.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query, string $userId, VerificationChannel $channel): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('channel', $channel->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now('UTC'))
            ->orderByDesc('created_at');
    }

    public function isExhausted(int $maxAttempts): bool
    {
        return $this->attempts >= $maxAttempts;
    }
}
