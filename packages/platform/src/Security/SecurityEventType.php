<?php

declare(strict_types=1);

namespace Kern\Platform\Security;

/** What a `security_events` row records. */
enum SecurityEventType: string
{
    case LOGIN_FAILED = 'LOGIN_FAILED';
    case MFA_CHALLENGE_FAILED = 'MFA_CHALLENGE_FAILED';
    case CROSS_TENANT_ACCESS_DENIED = 'CROSS_TENANT_ACCESS_DENIED';
}
