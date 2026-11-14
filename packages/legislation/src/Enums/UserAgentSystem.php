<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/** The system a `User-Agent` names, as the Browser card groups them. */
enum UserAgentSystem: string
{
    case WINDOWS = 'windows';
    case MACOS = 'macos';
    case LINUX = 'linux';
    case CHROMEOS = 'chromeos';
    case ANDROID = 'android';
    case IOS = 'ios';
}
