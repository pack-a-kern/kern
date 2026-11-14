<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Legislation\Enums\UserAgentBrowser;
use Kern\Legislation\Enums\UserAgentSystem;
use Kern\Platform\BrowserWorker\BrowserEngine;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A browser's `User-Agent` the Browser card offers, from the published list (`kern:sources:user-agents`).
 *
 * @property string $id
 * @property string $user_agent
 * @property BrowserEngine $engine
 * @property UserAgentSystem $system
 * @property UserAgentBrowser $browser
 * @property int $version
 * @property Carbon $published_at
 */
final class UserAgent extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_user_agents';

    /** @var list<string> */
    protected $fillable = ['user_agent', 'engine', 'system', 'browser', 'version', 'published_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'engine' => BrowserEngine::class,
            'system' => UserAgentSystem::class,
            'browser' => UserAgentBrowser::class,
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
