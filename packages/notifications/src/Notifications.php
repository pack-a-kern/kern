<?php

declare(strict_types=1);

namespace Kern\Notifications;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kern\Notifications\Models\Device;

/**
 * Which model holds the devices — the package's own unless the application encrypts them its way —
 * and a person's devices, through it.
 */
final class Notifications
{
    /** @var class-string<Device> */
    private static string $deviceModel = Device::class;

    /** @param class-string<Device> $model */
    public static function useDeviceModel(string $model): void
    {
        if ($model !== Device::class && ! is_subclass_of($model, Device::class)) {
            throw new InvalidArgumentException("[{$model}] does not extend " . Device::class . '.');
        }

        self::$deviceModel = $model;
    }

    /** @return class-string<Device> */
    public static function deviceModel(): string
    {
        return self::$deviceModel;
    }

    /** @return Builder<Device> */
    public static function devicesOf(Model $notifiable): Builder
    {
        return self::$deviceModel::query()->whereMorphedTo('notifiable', $notifiable);
    }
}
