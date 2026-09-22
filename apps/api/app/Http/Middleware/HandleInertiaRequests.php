<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Documents\FileSecurity\SniffedFileType;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Domain\Subjects\Queries\ListSubjectsForUser;
use App\Domain\Subjects\Queries\SubjectSummary;
use Illuminate\Http\Request;
use Inertia\Middleware;

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
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => $user === null ? null : [
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
                    ],
                    app(ListSubjectsForUser::class)($user->id)->items(),
                ),
            ],
            // What the upload picker may offer, from the one list that decides what the server
            // will actually accept. Shared rather than retyped in the dialog: a hand-maintained
            // copy drifts, and the way it drifts is a picker offering a file the server refuses.
            'documents' => [
                'accept' => SniffedFileType::acceptAttribute(),
                // Whether the upload screen may offer to clean up a photograph before sending it.
                'enhances' => app(AiProviderCatalogue::class)->supportsEnhancement(),
            ],
        ];
    }
}
