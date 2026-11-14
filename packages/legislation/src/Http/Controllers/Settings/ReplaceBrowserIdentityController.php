<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Settings;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kern\Legislation\Parsing\UserAgentListParser;
use Kern\Legislation\Queries\UserAgentChoices;
use Kern\Legislation\Services\BrowserIdentitySettings;
use Kern\Platform\BrowserWorker\BrowserEngine;
use Kern\Platform\Settings\Enums\SettingType;

/**
 * `PUT /api/v1/settings/browser/identity` — behind `verify-recent`, as every change to who this
 * installation says it is.
 *
 * A name from the list is saved only with its own engine. A name typed by hand is the operator's to vouch
 * for, so only on Chromium, the engine every other name falls back to: nothing here can tell what it is.
 */
final class ReplaceBrowserIdentityController
{
    public function __invoke(Request $request, BrowserIdentitySettings $settings, UserAgentChoices $choices): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::enum(BrowserEngine::class)],
            'user_agent' => ['required', 'string', 'max:' . UserAgentListParser::MAX_LENGTH, static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! SettingType::isHeaderValue($value)) {
                    $fail('The User-Agent must be one line of printable characters.');
                }
            }],
        ]);

        $engine = BrowserEngine::from((string) $validated['engine']);
        $userAgent = (string) $validated['user_agent'];

        if (($choices->engineOf($userAgent) ?? BrowserEngine::CHROMIUM) !== $engine) {
            throw ValidationException::withMessages(['user_agent' => ['This User-Agent is not a name the chosen browser engine sends.']]);
        }

        $settings->replace($engine, $userAgent, (string) $user->id);

        return response()->json(['engine' => $engine->value, 'user_agent' => $userAgent]);
    }
}
