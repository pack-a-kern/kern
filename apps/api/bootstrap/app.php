<?php

declare(strict_types=1);

use App\Http\Middleware\BindTaxSubjectContext;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\HandleInertiaRequests;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Kern\Platform\Identity\Exceptions\AccountSuspendedException;
use Kern\Platform\Identity\Http\Middleware\EndInactiveAccountSession;
use Kern\Platform\Identity\Http\Middleware\RecordSessionActivity;
use Kern\Platform\Identity\Http\Middleware\RequiresRecentVerification;
use Kern\Platform\Identity\Http\Middleware\RequireVerifiedEmail;
use Kern\Platform\Tenancy\Exceptions\UnauthorizedTenantAccessException;
use Kern\TaxRomania\Operations\DataTransferObjects\ScheduledTaskDefinition;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Jobs\RunOperationTask;
use Kern\TaxRomania\Operations\Services\ScheduledTaskRegistry;
use Kern\TaxRomania\Spv\Exceptions\SpvAlreadySignedInException;
use Kern\TaxRomania\Spv\Exceptions\SpvRunFailedException;
use Kern\TaxRomania\Spv\Exceptions\SpvSignInRequiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerAtCapacityException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousTaxRuleVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearIsFinalException;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearNotClosedException;
use Kern\TaxRomania\TaxEngine\Exceptions\FxRateUnavailableWithinLookbackException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRulesRegisteredException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRuleVersionInForceException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            // Before Inertia, so a suspended account's page request never renders with its data.
            EndInactiveAccountSession::class,
            // After the inactive-account check, so a session about to be revoked is not stamped as
            // active: records `last_used_at` on each authenticated request, keeping the session ledger
            // true so the prune does not delete a session its owner is still using.
            RecordSessionActivity::class,
            // On the whole group, not on route groups that each have to remember it: four routes once
            // did not, and answered an unverified account. It passes guests through and allows the
            // verification screen by name — see its allowed set.
            RequireVerifiedEmail::class,
            // Before Inertia renders the page: the nonce it allows has to exist when @vite writes its tags.
            ContentSecurityPolicy::class,
            HandleInertiaRequests::class,
        ]);
        /*
         * The order the framework sorts these into, wherever each is declared: signed in, then a
         * confirmed address, then the taxpayer, then the throttle, then the URL's models.
         *
         * An unverified account is told so before the taxpayer it named is even checked. The taxpayer
         * comes before the throttle because a limit counted per taxpayer has to know which one it is:
         * the framework puts its throttle ahead of route middleware, so without this the context was
         * still empty and every "per taxpayer" budget fell back to the address — one budget for an
         * office behind one router. And all of it before route-model binding, so a refused request
         * never loads the document or taxpayer its URL names.
         */
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireVerifiedEmail::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: BindTaxSubjectContext::class);
        // Posted by the browser itself, which holds no CSRF token for it — see that controller.
        $middleware->validateCsrfTokens(except: ['csp-report']);
        $middleware->alias([
            'tax-subject' => BindTaxSubjectContext::class,
            // Marked on the individual routes that need it, never on a group: the list of sensitive
            // acts is short, and a group that grew would quietly start demanding a code for reads.
            'verify-recent' => RequiresRecentVerification::class,
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
        // A login or a session belonging to an account that is no longer active. 403 with a closed reason,
        // so the sign-in screen can say it in the reader's language; the sentence is for the log. A page
        // navigation is sent to the sign-in screen instead, because a person needs to arrive somewhere.
        $exceptions->render(fn (AccountSuspendedException $exception, Request $request) => $request->expectsJson()
            ? response()->json(['message' => $exception->getMessage(), 'reason' => 'ACCOUNT_SUSPENDED'], 403)
            : redirect()->route('login'));

        $exceptions->render(fn (UnauthorizedTenantAccessException $exception): JsonResponse => response()->json(
            ['message' => 'You do not have access to this tax subject.'],
            403,
        ));

        // `findOrFail` and route-model binding throw `ModelNotFoundException`, which the framework
        // converts to a 404 carrying its default message — the fully-qualified model class — as the
        // previous exception, before any render callback runs. That message hands a caller the
        // application's internal namespace for a record that does not exist. A miss is a 404 and
        // nothing more; the class and id stay in the log. Only these model-backed 404s are rewritten
        // (so a deliberate 404 message elsewhere is kept), and only for JSON/API callers — a web
        // navigation still falls through to the error page.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?JsonResponse {
            if (! $exception->getPrevious() instanceof ModelNotFoundException) {
                return null;
            }

            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json(['message' => 'Not found.'], 404);
        });

        /*
         * The worker ran the playbook and a step did not work out — usually a selector matching nothing
         * because the portal's HTML has moved.
         *
         * 502, because the failure is in a dependency rather than in the request, and its own reason so
         * the screen does not say "unreachable" about a worker that answered. The worker's explanation
         * stays in the log: it names a playbook's outcomes, which is for whoever maintains the playbook.
         */
        $exceptions->render(fn (SpvRunFailedException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'SPV_RUN_FAILED'],
            502,
        ));

        /*
         * A year cannot be assessed because an exchange rate is missing, or because no rule version covers it.
         *
         * Both arrived as 500s, which is wrong twice over: nothing failed, and the two have different answers.
         * A missing rate is a gap in this installation's data and importing that year fixes it; a year with no
         * rule version is waiting for somebody to write the rules for it, and no amount of retrying helps.
         *
         * 409 for each: the request was fine and the state it asks about is not one it can be answered in.
         * The exception's own message carries the specifics — which currency, which date, which rule — because
         * a translated sentence cannot name them and the person needs them to know what to import.
         */
        $exceptions->render(fn (FxRateUnavailableWithinLookbackException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'FX_RATE_MISSING'],
            409,
        ));

        $exceptions->render(fn (NoTaxRuleVersionInForceException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'NO_RULES_FOR_YEAR'],
            409,
        ));

        /*
         * A year the fiscal dataset does not cover — a parameter with no version in force on the date being
         * assessed. Found because `RO.INCOME_TAX.RENT_EXPENSE_QUOTA` has a version for every year but 2023, and
         * each is closed at its year's end, so 2023 has a real hole.
         *
         * The engine refusing is right: an expense quota it does not hold is one it must not guess. But the
         * message names the parameter and the date, which is exactly what somebody needs in order to go and
         * approve it, and a 500 threw that away.
         */
        $exceptions->render(fn (NoFiscalParameterVersionInForceException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'FISCAL_PARAMETER_MISSING'],
            409,
        ));

        /*
         * Two rule versions in force on the same date. The resolver refuses rather than picking one, which is
         * right — an assessment that quietly chose between two readings of the law would be unexplainable.
         *
         * 409 and the ids, because fixing it means retiring one of them and somebody has to know which two.
         * Reached for real when a rule's version label was renamed and the old row kept its dates.
         */
        $exceptions->render(fn (AmbiguousTaxRuleVersionException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'AMBIGUOUS_RULE_VERSION'],
            409,
        ));

        $exceptions->render(fn (NoTaxRulesRegisteredException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'NO_RULES_REGISTERED'],
            409,
        ));

        /*
         * A change to a transaction in a year somebody marked final.
         *
         * 409 rather than 403: the caller is entitled to do this, the resource is in a state that refuses it,
         * and the difference matters to a screen deciding between "you may not" and "unlock the year first".
         * The year travels in the body so that screen can name it without parsing the sentence.
         */
        $exceptions->render(fn (FiscalYearIsFinalException $exception): JsonResponse => response()->json(
            [
                'message' => $exception->getMessage(),
                'reason' => 'FISCAL_YEAR_IS_FINAL',
                'fiscal_year' => $exception->fiscalYear,
            ],
            409,
        ));

        /*
         * Finalising a year that has not ended. 409 for the same reason: a request against a state, not a
         * permission, and one that becomes valid on its own on 1 January.
         */
        $exceptions->render(fn (FiscalYearNotClosedException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'FISCAL_YEAR_NOT_CLOSED'],
            409,
        ));

        /*
         * Reading the inbox needs a signed-in browser and there is not one — nobody signed in, the
         * sitting expired, or ANAF let it go mid-session.
         *
         * 409 rather than 403: nothing about the request was forbidden, the state is simply not one the
         * request makes sense in, and it is the person's to change. The same choice
         * `RequireVerifiedEmail` makes for an unverified address, for the same reason.
         */
        $exceptions->render(fn (SpvSignInRequiredException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'SPV_SIGN_IN_REQUIRED'],
            409,
        ));

        // The browser worker is unreachable, unconfigured, or answered something this does not
        // understand. None of those is a fault in the request: a 502 says the dependency is the
        // problem, which is what a person needs to know before they go looking at their own typing.
        $exceptions->render(fn (SpvWorkerUnavailableException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'SPV_WORKER_UNAVAILABLE'],
            502,
        ));

        // The worker is full and says so. 503, the worker's own answer, passed on: it is the dependency,
        // working, telling the caller to come back — a different thing to do from fixing a network.
        $exceptions->render(fn (SpvWorkerAtCapacityException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'SPV_WORKER_BUSY'],
            503,
        ));

        // A second sign-in on a connection that is signed in. 409: a request against a state, which the
        // screen already reflects by not offering it.
        $exceptions->render(fn (SpvAlreadySignedInException $exception): JsonResponse => response()->json(
            ['message' => $exception->getMessage(), 'reason' => 'SPV_ALREADY_SIGNED_IN'],
            409,
        ));
    })->create();
