<?php

declare(strict_types=1);

namespace App\Domain\Declarations;

use App\Domain\Declarations\ArtifactGeneration\PlaceholderArtifactGenerator;
use App\Domain\Declarations\Contracts\DeclarationArtifactGenerator;
use Illuminate\Support\ServiceProvider;

/**
 * Binds `App\Domain\Declarations\Contracts\DeclarationArtifactGenerator` to the one
 * implementation this task ships, `App\Domain\Declarations\ArtifactGeneration\
 * PlaceholderArtifactGenerator` — see that class's docblock for why it is explicitly not a real
 * ANAF-accepted artifact. **Replacing this one binding is the entire migration path** once a real
 * D212 submission format is obtained (`docs/STATUS.md` blocked item 2); nothing else in this
 * module needs to change.
 *
 * `bind()`, not `singleton()`: the generator is stateless and cheap to construct, matching
 * `Kern\Legislation\TaxKnowledgeServiceProvider`'s identical reasoning for its own
 * adapter bindings (`docs/handoff/023-taxengine-boundary-inversion.md`).
 */
final class DeclarationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeclarationArtifactGenerator::class, PlaceholderArtifactGenerator::class);
    }
}
