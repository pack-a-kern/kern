<?php

declare(strict_types=1);

namespace Kern\Notifications\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;

/**
 * Somebody in an application that is not this one: integer ids, a table of its own name, Laravel's
 * stock `Notifiable` — what a fresh `laravel new` has.
 *
 * @property int $id
 */
final class Person extends User
{
    use Notifiable;

    protected $table = 'people';

    /** @var list<string> */
    protected $fillable = ['name'];
}
