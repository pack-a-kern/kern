<?php

declare(strict_types=1);

namespace Kern\Platform\Routing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Kern\Platform\Tenancy\TenantContext;

/**
 * A named rate limiter whose numbers live in config.
 *
 * Every package that throttles a route needs the same three lines — read `max_attempts` and
 * `decay_seconds` from its own config, pick a key, build a `Limit` — and two packages had written
 * them out separately before a third was due to. One copy, so a limiter is a name, a config path
 * and a key, and nothing about the arithmetic can drift between them.
 *
 * The config path must hold both numbers. It is read on every request, not at boot, so a value
 * changed in config takes effect without re-registering anything.
 */
final class ConfiguredRateLimit
{
    /** @param callable(Request): string $key */
    public static function register(string $name, string $configPath, callable $key): void
    {
        RateLimiter::for($name, static fn (Request $request): Limit => new Limit(
            $key($request),
            config()->integer("{$configPath}.max_attempts"),
            config()->integer("{$configPath}.decay_seconds"),
        ));
    }

    /**
     * One budget per account. Falls back to the address when there is no account, which behind
     * `auth:web` cannot happen — but a limiter keyed on an empty string would pool every caller into
     * one bucket, and that is the failure worth making impossible.
     *
     * @return callable(Request): string
     */
    public static function perUser(): callable
    {
        return static function (Request $request): string {
            $identifier = $request->user()?->getAuthIdentifier();

            return is_string($identifier) ? $identifier : (string) $request->ip();
        };
    }

    /**
     * One budget per tenant the request acts for, read from the bound context rather than from a
     * route parameter whose name is the application's. That needs the tenant middleware to run before
     * the throttle, which the framework does not do on its own — it sorts its throttle ahead of route
     * middleware — so the application puts it there in its middleware priority. The address is the
     * answer for a request that somehow arrives without one.
     *
     * @return callable(Request): string
     */
    public static function perTenant(): callable
    {
        return static function (Request $request): string {
            $context = app(TenantContext::class);

            return $context->hasActiveTenant() ? $context->currentTenantId() : (string) $request->ip();
        };
    }
}
