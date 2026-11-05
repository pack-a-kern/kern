<?php

declare(strict_types=1);

namespace Kern\Notifications\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who a request is for and which language to answer in — the two things every endpoint here asks.
 *
 * The language is the page's own (`?locale=`), not a guess from the browser: the bell and the
 * switches speak whatever the person chose inside the application.
 */
final class Person
{
    public static function of(Request $request): Model
    {
        $user = $request->user();
        abort_unless($user instanceof Model, 401);

        return $user;
    }

    public static function locale(Request $request): string
    {
        $locale = $request->query('locale');

        return is_string($locale) && preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : app()->getLocale();
    }
}
