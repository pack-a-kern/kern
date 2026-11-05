<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Notifications;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * A key pair for device notifications (VAPID, RFC 8292), printed as the two lines `apps/api/.env` takes.
 *
 * Printed, not written: the environment file is the operator's, and replacing a pair stops every device
 * already subscribed under the old one — which is a decision to make, not a side effect of a command.
 */
final class GeneratePushKeysCommand extends Command
{
    protected $signature = 'kern:push:keys';

    protected $description = 'Make a key pair for device notifications and print the lines for apps/api/.env.';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('WEB_PUSH_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('WEB_PUSH_PRIVATE_KEY=' . $keys['privateKey']);
        $this->newLine();
        $this->comment('Put both in apps/api/.env and recreate app, queue and scheduler. A new pair stops every device subscribed under the old one until it turns notifications on again.');

        return self::SUCCESS;
    }
}
