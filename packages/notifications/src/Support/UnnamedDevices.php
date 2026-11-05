<?php

declare(strict_types=1);

namespace Kern\Notifications\Support;

use Kern\Notifications\Contracts\DescribesDevices;
use Kern\Notifications\DataTransferObjects\DeviceDescription;

/** What a device is called when the application names none: nothing, and the page says "a device". */
final readonly class UnnamedDevices implements DescribesDevices
{
    public function describe(?string $userAgent): DeviceDescription
    {
        return new DeviceDescription();
    }
}
