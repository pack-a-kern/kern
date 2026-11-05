<?php

declare(strict_types=1);

namespace Kern\Notifications;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kern\Notifications\Console\GeneratePushKeysCommand;
use Kern\Notifications\Contracts\DescribesDevices;
use Kern\Notifications\Services\NoticeTypes;
use Kern\Notifications\Support\UnnamedDevices;

/**
 * Everything the package needs, wired by requiring it: its tables, its endpoints, its texts, the
 * kinds-of-notice list the application registers into, and the command that makes the keys.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/notifications.php', 'notifications');

        // One list for the whole process: whoever registers a kind adds it here.
        $this->app->singleton(NoticeTypes::class);
        $this->app->bindIf(DescribesDevices::class, UnnamedDevices::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'notifications');
        $this->registerRateLimits();

        if (! $this->app->routesAreCached()) {
            Route::middleware(config()->array('notifications.routes.middleware'))
                ->prefix(config()->string('notifications.routes.prefix'))
                ->group(__DIR__ . '/../routes/api.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([GeneratePushKeysCommand::class]);
            $this->publishes([__DIR__ . '/../config/notifications.php' => config_path('notifications.php')], 'notifications-config');
        }
    }

    /** Per person, from `notifications.rate_limits`. */
    private function registerRateLimits(): void
    {
        foreach (['devices', 'test'] as $name) {
            RateLimiter::for("notifications-{$name}", static function (Request $request) use ($name): Limit {
                $id = $request->user()?->getAuthIdentifier();

                return new Limit(
                    is_scalar($id) ? (string) $id : (string) $request->ip(),
                    config()->integer("notifications.rate_limits.{$name}.max_attempts"),
                    config()->integer("notifications.rate_limits.{$name}.decay_seconds"),
                );
            });
        }
    }
}
