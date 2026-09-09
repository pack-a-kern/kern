<?php

declare(strict_types=1);

use App\Domain\Settings\Enums\SettingKey;
use Illuminate\Support\Facades\Config;

/**
 * Every setting names the config path it overrides, and that path must actually exist — otherwise
 * clearing a database override leaves nothing behind instead of restoring the previous behaviour,
 * and the screen shows an inherited value that no code ever reads.
 *
 * `Config::has()`, not `config() !== null`: a declared path may legitimately hold null (no AI
 * provider is configured yet — `docs/STATUS.md` blocked item 3). A missing path and a null value
 * are different failures, and only the first is a bug here.
 */
it('declares a config path that exists for every setting', function (): void {
    $missing = [];

    foreach (SettingKey::cases() as $key) {
        if (! Config::has($key->configPath())) {
            $missing[] = "{$key->value} -> {$key->configPath()}";
        }
    }

    expect($missing)->toBe([]);
});
