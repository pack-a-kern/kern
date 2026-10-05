<?php

declare(strict_types=1);

use App\Http\Middleware\BindTaxSubjectContext;
use App\Http\Middleware\HandleInertiaRequests;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Kern\Platform\Identity\Http\Middleware\RequireVerifiedEmail;
use Kern\Platform\Identity\Exceptions\MfaNotEnrolledException;
use Kern\Platform\Tenancy\Exceptions\UnauthorizedTenantAccessException;
use Kern\TaxRomania\Operations\DataTransferObjects\ScheduledTaskDefinition;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Jobs\RunOperationTask;
use Kern\TaxRomania\Operations\Services\ScheduledTaskRegistry;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // routes/api.php is deliberately wrapped in the "web" middleware group, not
            // Laravel's own stateless "api" group `withRouting(api: ...)` would apply — this
            // project's authentication is session-cookie based (config/auth.php's docblock,
            // docs/handoff/017-authentication.md "Key decisions"), so every /api/v1 request
            // needs the same StartSession/EncryptCookies/CSRF stack a first-party SPA relies on,
            // not the api group's stateless defaults.
            Route::middleware('web')
                ->prefix('api')
                ->group(__DIR__ . '/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'tax-subject' => BindTaxSubjectContext::class,
            // Applied to the authenticated groups in routes/web.php and to the package's own routes,
            // never globally: a guest must be told to sign in, and the verification screen and the
            // endpoints it calls have to stay reachable. See that middleware's allowed set.
            'verified' => RequireVerifiedEmail::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // A heartbeat the container's healthcheck reads, so "the scheduler is alive" is answered by
        // the scheduler itself rather than by looking for its process.
        //
        // The previous check ran `pgrep -f schedule:work`, and `pgrep` is not installed in this
        // image — so it could never succeed and the container reported unhealthy from its first
        // check onward, whatever the scheduler was doing. A permanently red signal carries no
        // information: a genuine failure looked exactly like the healthy state.
        //
        // Touching a file also detects the failure a process check cannot see. `schedule:work` can
        // be running and wedged — a stuck subprocess, a lock never released — and its process
        // stays perfectly alive. If the loop stops dispatching, this file stops being touched and
        // the mtime goes stale within minutes.
        // In /tmp, not in storage/: a liveness signal must not outlive the process it describes.
        // A heartbeat left on a persistent volume would still read "recent" for three minutes after
        // the container died, which is the one moment the check exists to catch. /tmp is also the
        // only path this hardened image lets the runtime user write to.
        // Beats into two places, because two audiences ask "is the scheduler alive?" for different
        // reasons and must be able to fail independently:
        //
        //  - the **file**, read by `docker/php/scheduler-healthcheck.php` inside this same
        //    container. Local on purpose: a healthcheck that went red because Redis was down would
        //    restart a scheduler that is working perfectly.
        //  - the **cache**, read by `Kern\TaxRomania\Operations\Services\ProbeSchedulerStatus` in the
        //    `app` container for /admin/operatiuni. That is a *different* container with its own
        //    tmpfs — it cannot see the file at all, and a screen that reported a healthy scheduler
        //    as stalled would be worse than no screen.
        //
        // Neither write may throw. This closure runs before every other scheduled entry, and an
        // exception here would abort the whole tick — so a full disk or an unreachable Redis would
        // stop the imports as well as the heartbeat, which is the exact failure this is meant to
        // detect rather than cause.
        $schedule->call(static function (): void {
            $path = config('operations.scheduler.heartbeat_path');

            if (is_string($path) && $path !== '') {
                // `touch` rather than a write: only the mtime is ever read, and this runs every
                // minute for the life of the container.
                @touch($path);
            }

            $key = config('operations.scheduler.heartbeat_cache_key');
            $ttl = config('operations.scheduler.heartbeat_cache_ttl_seconds');

            if (is_string($key) && $key !== '' && is_int($ttl) && $ttl > 0) {
                try {
                    // A timestamp rather than a bare marker: an expired key can only say "stalled",
                    // while a stored value can say how long it has been stalled for.
                    //
                    // Stored as an ISO-8601 *string*, never as an integer. Laravel's Redis cache
                    // store passes numeric values through unserialized, so an int written here
                    // comes back as the string "1788949200" — which a strict `is_int()` read
                    // rejects, and the screen then reports a healthy scheduler as stalled. A
                    // non-numeric string round-trips identically through every cache driver.
                    Cache::put($key, CarbonImmutable::now('UTC')->toIso8601String(), $ttl);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        })->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();

        // Everything below the heartbeat comes from `ScheduledTaskRegistry`, not from literals
        // here. The split it enforces is the security property, and it is worth stating plainly:
        //
        //   *which* tasks exist  -> Kern\TaxRomania\Operations\Enums\OperationTask, a closed PHP enum
        //   *when* they run      -> config/operations.php, env-overridable
        //   *whether* they run   -> scheduled_tasks.enabled, the one runtime-mutable bit
        //
        // So an administrator can pause the BNR import from /admin/operatiuni and it stops within
        // a minute — the `scheduler` container runs `schedule:work`, which spawns a fresh
        // `schedule:run` every tick, re-evaluating this closure each time. What an administrator
        // cannot do is add a command or retime one from a web form: the first is remote code
        // execution behind a nice UI, and the second is how a job silently stops running forever.
        //
        // This closure must not be allowed to throw. If it did — because the database was briefly
        // unreachable while reading the enabled flags — `schedule:run` would abort and *nothing*
        // would run this minute, including the heartbeat above that reports the scheduler healthy.
        // `ScheduledTaskRegistry` therefore degrades to the code-declared defaults rather than
        // propagating; the `try` here covers the container resolution itself for the same reason.
        try {
            $definitions = app(ScheduledTaskRegistry::class)->schedulable();
        } catch (\Throwable $exception) {
            report($exception);
            $definitions = [];
        }

        foreach ($definitions as $definition) {
            /** @var ScheduledTaskDefinition $definition */
            $cronExpression = $definition->cronExpression;

            if ($cronExpression === null) {
                continue;
            }

            // Dispatched as a job rather than run with `$schedule->command(...)` so that a
            // scheduled run and an operator's "Run now" travel the identical path: the same lock,
            // the same `job_runs` ledger row, the same recorded exit code. A ledger that only
            // covered manual runs could not answer "did last night's import work", which is the
            // question this whole screen exists for. It also keeps the scheduler tick cheap — the
            // import itself happens on a queue worker, not inside `schedule:run`.
            $schedule->job(new RunOperationTask($definition->task, TaskRunTrigger::SCHEDULED, null))
                ->cron($cronExpression)
                ->timezone($definition->timezone)
                ->name('operations:' . $definition->task->value)
                ->withoutOverlapping();
        }

        // Legislation ingestion is deliberately NOT scheduled. `kern:legislation:ingest` takes an
        // explicit {source} {url} pair; there is no manifest of "documents to periodically
        // re-check" in this codebase yet (Milestone 3 built the capability, not a watch list —
        // docs/handoff/018-legislation-ingestion.md "What is NOT done"), and inventing one to give
        // a scheduler entry something to iterate over would be exactly the kind of speculative
        // scaffolding docs/CODE-QUALITY.md §2 forbids. `docs/AGENTS.md` rule 17 and that task's
        // brief are explicit that re-fetching a multi-megabyte document on a tight loop is abusive
        // to a public service — the defensible cadence for a single, specifically-named document
        // (once one is chosen operationally) is weekly at most, off-peak. It is absent from
        // `OperationTask` too, and for a second reason: it takes a URL and a document type, which
        // is an ingestion decision with a provenance consequence (BLUEPRINT.md §7.1), not a button.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The tenant binding throws this the moment a user holds no role for `{subject}` — a
        // cross-tenant access attempt, not a server fault. Rendered centrally so every module's
        // HTTP layer gets the same 403 without importing the exception into its own controllers
        // to catch it (docs/AGENTS.md §6).
        $exceptions->render(fn (UnauthorizedTenantAccessException $exception): JsonResponse => response()->json(
            ['message' => 'You do not have access to this tax subject.'],
            403,
        ));

        // §4.7 — an account must enrol MFA before it may connect SPV. A refusal, not a fault: the
        // person has to go and do something, and a 500 would tell them the installation is broken.
        // Rendered here for the same reason as the one above — every call site gets it without any
        // controller importing the exception to catch it.
        $exceptions->render(fn (MfaNotEnrolledException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'MFA_REQUIRED'],
            403,
        ));
    })->create();
