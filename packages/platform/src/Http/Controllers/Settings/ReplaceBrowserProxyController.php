<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\BrowserWorker\RegisteredPlaybooks;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Services\BrowserProxySettingsService;

/**
 * `PUT /api/v1/settings/browser/proxy` — behind `verify-recent`: a proxy sees where every browser it
 * carries goes, so pointing them at one asks for the password or code again.
 *
 * On needs the address, the user name and the password, each refused by name when missing. Off takes
 * none and empties all three. The playbooks are those the packages registered, and no other name.
 */
final class ReplaceBrowserProxyController
{
    public function __invoke(Request $request, BrowserProxySettingsService $service, RegisteredPlaybooks $playbooks): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // `http://host:port` alone: the credentials travel beside it, never inside it.
            'server' => ['required_if:enabled,true', 'nullable', 'string', 'max:255', 'regex:#^http://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$#'],
            'username' => ['required_if:enabled,true', 'nullable', 'string', 'max:255', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! SettingType::isHeaderValue($value)) {
                    $fail('The user name must be one line of printable characters.');
                }
            }],
            'password' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'playbooks' => ['present', 'array'],
            'playbooks.*' => ['string', 'distinct', Rule::in($playbooks->names())],
        ]);

        /** @var list<string> $chosen */
        $chosen = array_values($validated['playbooks']);

        return response()->json($service->replace(
            enabled: (bool) $validated['enabled'],
            server: is_string($validated['server'] ?? null) ? rtrim($validated['server'], '/') : null,
            username: is_string($validated['username'] ?? null) ? $validated['username'] : null,
            password: is_string($validated['password'] ?? null) ? $validated['password'] : null,
            playbooks: $chosen,
            actorUserId: (string) $user->id,
        ));
    }
}
