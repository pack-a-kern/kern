<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Kern\Documents\FileSecurity\SniffedFileType;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\WatchSystemHealth;
use Kern\TaxRomania\Fx\Services\CurrencyCatalogue;
use Kern\TaxRomania\Subjects\Queries\ListSubjectsForUser;
use Kern\TaxRomania\Subjects\Queries\SubjectSummary;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * What the shell needs on every screen, carried by the page itself.
     *
     * The sidebar names the signed-in account and the taxpayer every figure on the page belongs to,
     * so it needed both before it could draw anything — and fetched them, once per page load, after
     * the page had already arrived. The same two queries run either way; sharing them here spends
     * them while the response is being built instead of on a second round trip the reader waits
     * through and watches fill in.
     *
     * Deliberately small. Anything a single screen needs stays on that screen's own endpoint: this
     * runs for *every* Inertia response, including the ones that care about none of it, so it earns
     * its place only for what the shell itself draws.
     *
     * ## Closures, not values
     *
     * Inertia calls this for every request through the `web` group — and `/api` is in that group, so
     * every JSON call too. A value here is built whether or not a page is rendered: the roles, the
     * permissions, the taxpayer switcher and every name decrypted for it, a key unwrapped from
     * OpenBao for each. A closure is resolved only when a page is, so an API call pays for none of it.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            /*
             * The product's name, from `APP_NAME` and nowhere else.
             *
             * Shared rather than built into the assets: the frontend used to carry its own copy in
             * `VITE_APP_NAME`, which meant renaming the application needed the frontend rebuilt, and
             * a second copy sat in the `ro`/`en` dictionaries as though a name were translatable.
             * From here it reaches the shell, the login screen and the tab title at runtime.
             */
            'appName' => config('app.name'),
            'auth' => static fn (): ?array => $user === null ? null : [
                /*
                 * Whether this session is somebody else's.
                 *
                 * An administrator acting as another person looks, to every screen, exactly like
                 * that person — which is the point, and the danger. The shell draws a banner from
                 * this, so the state is never invisible and the way back is always one click away.
                 * Read from the session rather than a cookie: the impersonated user must not be
                 * able to edit their way out of being marked as impersonated.
                 */
                'impersonated' => $request->session()->has('auth.impersonator_id'),
                'user' => [
                    'id' => $user->id,
                    // Decrypted on read by the model's cast — the column itself is ciphertext.
                    'email' => $user->email_ciphertext,
                    'roles' => $user->getRoleNames()->all(),
                    // For drawing, never for deciding: a button a person may not use is hidden,
                    // and every route still checks the permission itself.
                    'permissions' => $user->getAllPermissions()->pluck('name')->all(),
                ],
                // The first page only: a switcher is a shortcut, not a directory. Someone holding
                // more taxpayers than that manages them on the taxpayers screen, which paginates.
                'subjects' => array_map(
                    static fn (SubjectSummary $summary): array => [
                        'id' => $summary->id,
                        'display_name' => $summary->displayName,
                        'status' => $summary->status->value,
                        'type' => $summary->type->value,
                        // The switcher draws the taxpayer on every page, so the picture's cache key
                        // travels with the name rather than costing a request per page load.
                        'avatar_updated_at' => $summary->avatarUpdatedAt,
                    ],
                    app(ListSubjectsForUser::class)($user->id)->items(),
                ),
            ],
            /*
             * The currencies this installation works in, for the same reason as the line below it.
             *
             * Seven screens record an amount and every one of them needs this list; it used to be two
             * arrays written into the frontend — `types/wallet.ts` and the dashboard's own copy — which
             * disagreed with each other and with an API that accepts any ISO 4217 code. Shared from the
             * setting, so changing it is a save rather than a deploy, and so there is one list.
             *
             * A config read, not a query: five strings off a setting that is already overlaid onto
             * config at boot. The available-currency list, which does read the rates, stays on the
             * settings screen's own endpoint.
             */
            'currencies' => static fn (): array => app(CurrencyCatalogue::class)->enabled(),
            /*
             * Which services were down at the scheduler's last look, for the strip across the top —
             * only for those who may read the system status, exactly as the status screen is.
             *
             * The kept answer, not a probe: one cache read per page (`WatchSystemHealth::down()`).
             * Changes after the page loaded arrive over the WebSocket, so nothing polls for this.
             */
            'systemHealth' => static fn (): ?array => $user !== null && $user->can('system.settings.view')
                ? ['down' => app(WatchSystemHealth::class)->down()]
                : null,
            // What the upload picker may offer, from the one list that decides what the server
            // will actually accept. Shared rather than retyped in the dialog: a hand-maintained
            // copy drifts, and the way it drifts is a picker offering a file the server refuses.
            'documents' => static fn (): array => [
                'accept' => SniffedFileType::acceptAttribute(),
                // Whether the upload screen may offer to clean up a photograph before sending it.
                'enhances' => app(AiProviderCatalogue::class)->supportsEnhancement(),
            ],
        ];
    }
}
