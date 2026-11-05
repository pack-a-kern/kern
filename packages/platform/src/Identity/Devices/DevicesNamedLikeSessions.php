<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Devices;

use Kern\Notifications\Contracts\DescribesDevices;
use Kern\Notifications\DataTransferObjects\DeviceDescription;
use Kern\Platform\Identity\Support\DeviceSummary;

/** A device that receives notices is named the way the list of signed-in sessions names it. */
final readonly class DevicesNamedLikeSessions implements DescribesDevices
{
    public function describe(?string $userAgent): DeviceDescription
    {
        $summary = DeviceSummary::read($userAgent);

        return new DeviceDescription($summary->kind->value, $summary->browser, $summary->platform);
    }
}
