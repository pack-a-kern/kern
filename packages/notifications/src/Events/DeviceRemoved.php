<?php

declare(strict_types=1);

namespace Kern\Notifications\Events;

use Illuminate\Database\Eloquent\Model;

/** A person turned notices off for one of their devices. For an application that keeps an audit trail. */
final readonly class DeviceRemoved
{
    public function __construct(public Model $notifiable, public string $deviceId)
    {
    }
}
