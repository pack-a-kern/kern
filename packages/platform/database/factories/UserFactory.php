<?php

declare(strict_types=1);

namespace Kern\Platform\Database\Factories;

use function app;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

/**
 * @extends Factory<User>
 *
 * `definition()` deliberately sets no encrypted column — a `User`'s own DEK
 * (`encryptionSubjectId()` returns its own id) can only be created once the row already exists in
 * the database, exactly as `Kern\Platform\Identity\Models\User::createWithEmail()`'s docblock
 * explains for the identical reason `Kern\TaxRomania\Database\Factories\TaxSubjectFactory` gives. `configure()`'s
 * `afterCreating()` hook assigns a random unique email in a second `save()`, after the row exists.
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'password_hash' => null,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $email = $this->faker->unique()->safeEmail();
            $user->email_ciphertext = $email;
            $user->email_blind_index = app(BlindIndexHasher::class)->compute(Normalizer::email($email));
            $user->save();
        });
    }

    /**
     * Overrides `configure()`'s random default with a specific email — the resulting row still
     * gets exactly one final email, at the cost of one harmless extra `UPDATE` (the default from
     * `configure()` runs first, then this callback overwrites it): acceptable in a test factory,
     * where correctness of the final state matters far more than the number of statements.
     */
    public function withEmail(string $email): static
    {
        return $this->afterCreating(function (User $user) use ($email): void {
            $user->email_ciphertext = $email;
            $user->email_blind_index = app(BlindIndexHasher::class)->compute(Normalizer::email($email));
            $user->save();
        });
    }
}
