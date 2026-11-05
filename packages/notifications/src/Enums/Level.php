<?php

declare(strict_types=1);

namespace Kern\Notifications\Enums;

/** Whether a notice reports something that went wrong — the bell marks those apart. */
enum Level: string
{
    case Info = 'info';
    case Error = 'error';
}
