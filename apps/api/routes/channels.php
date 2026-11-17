<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Events\SystemHealthChanged;
use Kern\Platform\Tenancy\Contracts\TenantGate;

/*
 * Who may listen to a tenant's channels — asked of the gate, not of a table.
 *
 * `TenantGate` is the same question `TenantContext::authorizeAndActivate()` asks on every request,
 * and asking it directly is right here for the reason that context is not: a broadcasting-auth
 * request has no tenant to bind, it is answering "may this user listen", not "act as this taxpayer".
 * Going through the contract rather than querying `subject_memberships` keeps this file — the
 * application's — out of a package's Eloquent models, and means a refused subscription is written to
 * `security_events` like every other refusal.
 *
 * A closure in a variable, not a named function: this file is required once per application
 * instance, and a test suite that boots a hundred of them in one process would redeclare it.
 */
$mayListenToTenant = static fn (User $user, string $tenantId): bool => app(TenantGate::class)
    ->roleFor($user->id, $tenantId) !== null;

/*
 * `Kern\Documents\Events\*` broadcast on `tax-subjects.{subjectId}.documents` (`BLUEPRINT.md` §16).
 */
Broadcast::channel(
    'tax-subjects.{subjectId}.documents',
    $mayListenToTenant,
);

/*
 * `Kern\TaxRomania\StatementImport\Events\StatementImportChanged` — an import's progress belongs to
 * the taxpayer whose statement it is.
 */
Broadcast::channel(
    'tax-subjects.{subjectId}.imports',
    $mayListenToTenant,
);

/*
 * `Kern\TaxRomania\Spv\Events\*` — ANAF's own messages for this taxpayer (`BLUEPRINT.md` §16).
 */
Broadcast::channel(
    'tax-subjects.{subjectId}.spv',
    $mayListenToTenant,
);

/*
 * A user's own notices (`User::receivesBroadcastNotificationsOn()`). Only that user may listen: the
 * notices say which of their imports moved, and are nobody else's business.
 */
Broadcast::channel(
    'users.{userId}',
    fn (User $user, string $userId): bool => $user->id === $userId,
);

/*
 * `Kern\Platform\Settings\Events\SystemHealthChanged` — which services stopped answering. For the
 * people who may read the system status, exactly as the status screen is (`system.settings.view`).
 */
Broadcast::channel(
    SystemHealthChanged::CHANNEL,
    fn (User $user): bool => $user->can('system.settings.view'),
);

/*
 * `Kern\TaxRomania\Operations\Events\OperationsActivityChanged` — a nudge that the queue activity
 * changed, for the topbar indicator. For the people who may read the operations screen
 * (`operations.view`), the same permission its endpoint checks. The channel name is read from config
 * so it matches the event's; empty there means it is broadcast nowhere and this authorizes nothing.
 */
$operationsChannel = config('operations.activity.broadcast_channel');

if (is_string($operationsChannel) && $operationsChannel !== '') {
    Broadcast::channel(
        $operationsChannel,
        fn (User $user): bool => $user->can('operations.view'),
    );
}
