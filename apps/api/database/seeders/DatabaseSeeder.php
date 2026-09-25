<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Nothing, on purpose.
     *
     * Every list this product needs is either configuration (`reference/*`, the official-source
     * allowlist) projected onto tables by a command that can be re-run, or real data fetched from the
     * institution that publishes it — banks from the ECB, rates from the BNR, the law from the
     * portal. Seeded rows would be a fourth source of truth for figures that must be traceable to
     * the body that issued them.
     */
    public function run(): void
    {
        //
    }
}
