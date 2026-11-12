<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * An act a watch had read in, deleted by a person who asked the watches to pass it over from then on.
 * Every watch skips it; reading it again by hand ("Add a law"), or "Allow again", takes it off.
 *
 * @property string $id
 * @property string $portal_document_id  the act on legislatie.just.ro, the only thing a search knows of it
 * @property string $title  the public act's, so the list says what it is
 * @property string|null $ignored_by
 * @property Carbon $created_at
 */
final class IgnoredAct extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_ignored_acts';

    /** @var list<string> */
    protected $fillable = ['portal_document_id', 'title', 'ignored_by'];
}
