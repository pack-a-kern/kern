<?php

declare(strict_types=1);

namespace Kern\Notifications\Contracts;

use Kern\Notifications\DataTransferObjects\DeviceDescription;

/**
 * How a device is named in the person's list of devices, from the browser's `User-Agent`.
 *
 * The application's to answer, since it is likely to name devices already — in a list of signed-in
 * sessions, say — and the two lists should agree. With nothing bound, a device has no name
 * (`Support\UnnamedDevices`).
 */
interface DescribesDevices
{
    public function describe(?string $userAgent): DeviceDescription;
}
