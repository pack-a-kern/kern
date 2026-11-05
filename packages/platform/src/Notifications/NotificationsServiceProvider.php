<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\Console\Commands\Notifications\GeneratePushKeysCommand;
use Kern\Platform\Notifications\Services\NotificationTypeRegistry;
use Kern\Platform\Routing\ConfiguredRateLimit;

/**
 * Device notifications: the catalogue of kinds packages declare, the channel that sends them, and the
 * account's own devices and choices (`routes/identity.php`, under `v1/notifications`).
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/notifications.php', 'notifications');

        // One catalogue for the whole process: every package's provider adds its kinds to it.
        $this->app->singleton(NotificationTypeRegistry::class);
    }

    public function boot(): void
    {
        $perUser = static function (Request $request): string {
            $id = $request->user()?->getAuthIdentifier();

            return is_string($id) ? $id : (string) $request->ip();
        };

        ConfiguredRateLimit::register('push-devices-per-user', 'notifications.rate_limits.push_devices_per_user', $perUser);
        ConfiguredRateLimit::register('push-test-per-user', 'notifications.rate_limits.push_test_per_user', $perUser);

        if ($this->app->runningInConsole()) {
            $this->commands([GeneratePushKeysCommand::class]);
        }
    }
}
