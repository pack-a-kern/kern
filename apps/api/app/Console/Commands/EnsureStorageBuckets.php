<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Says whether every bucket this installation writes to exists, and creates the ones that do not.
 *
 * Provider-agnostic on purpose: `HeadBucket` and `CreateBucket` are S3 API calls, so this works
 * against MinIO, Amazon S3, or any other implementation of that API — `config/filesystems.php` is
 * the only thing that decides which. It replaces knowing a particular provider's CLI.
 *
 * ## Why this is a command and not something an upload does
 *
 * A bucket that does not exist is the quietest failure this application has. `config/filesystems.php`
 * sets `'throw' => false`, so the S3 driver answers a missing bucket with a silent `false`, and the
 * first symptom is a 500 on an upload with nothing naming the cause. That happened: `kern-quarantine`
 * had never been created on any machine.
 *
 * The application's key is allowed to create a bucket — see `deploy/storage/create-app-key.sh`, which
 * grants it on every name and withholds `DeleteBucket` — precisely so that this command can be the
 * thing that does it. It is still a command rather than something an upload notices, because creating
 * a bucket happens once in the life of a deployment and a request should not carry a step that either
 * costs nothing or changes the shape of the storage. Run it when a deployment is new, or when a
 * release adds a disk; the answer the rest of the time is "every configured bucket exists".
 *
 * ## The checks
 *
 * `HeadBucket` distinguishes three answers, and they call for different things:
 *   - **200** — it is there and this key can see it.
 *   - **404** — it is not there. `--create` makes it.
 *   - **403** — it exists but this key may not look at it, OR the key lacks `s3:ListBucket`. Either
 *     way creating it is wrong: on Amazon the name is already taken, possibly by another account.
 *
 * Reported, never guessed at, because "no permission" and "does not exist" have opposite remedies.
 */
final class EnsureStorageBuckets extends Command
{
    protected $signature = 'storage:buckets {--create : Create the buckets that do not exist yet}';

    protected $description = 'Check that every S3 bucket this installation uses exists, and optionally create the missing ones';

    public function handle(ConfigRepository $config): int
    {
        $missing = [];
        $failed = [];

        /** @var array<string, array<string, mixed>> $disks */
        $disks = $config->array('filesystems.disks');

        foreach ($disks as $name => $definition) {
            if (($definition['driver'] ?? null) !== 's3') {
                continue;
            }

            $bucket = is_string($definition['bucket'] ?? null) ? $definition['bucket'] : '';

            if ($bucket === '') {
                $failed[] = "{$name}: no bucket configured";

                continue;
            }

            $disk = Storage::disk($name);

            // Every `s3` disk resolves to this adapter; the guard is what lets the client be typed.
            if (! $disk instanceof AwsS3V3Adapter) {
                $failed[] = "{$name}: not an S3 disk at runtime";

                continue;
            }

            $client = $disk->getClient();

            try {
                $client->headBucket(['Bucket' => $bucket]);
                $this->line("  <fg=green>✓</> {$name} → {$bucket}");

                continue;
            } catch (AwsException $e) {
                if ($e->getStatusCode() !== 404) {
                    $failed[] = sprintf('%s → %s: %s', $name, $bucket, self::reason($e));

                    continue;
                }
            }

            if (! $this->option('create')) {
                $missing[] = "{$name} → {$bucket}";
                $this->line("  <fg=yellow>?</> {$name} → {$bucket} does not exist");

                continue;
            }

            try {
                // Amazon requires the region named for every region but us-east-1, and rejects it
                // *for* us-east-1; every other implementation ignores it. Region-less configuration
                // (a provider that has no regions) sends no constraint at all.
                $region = is_string($definition['region'] ?? null) ? $definition['region'] : '';
                $arguments = ['Bucket' => $bucket];

                if ($region !== '' && $region !== 'us-east-1') {
                    $arguments['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
                }

                $client->createBucket($arguments);
                $this->line("  <fg=green>+</> {$name} → {$bucket} created");
            } catch (AwsException $e) {
                // Two providers race, or it was already ours: the end state is the one we wanted.
                if (in_array($e->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
                    $this->line("  <fg=green>✓</> {$name} → {$bucket} (created by someone else meanwhile)");

                    continue;
                }

                $failed[] = sprintf('%s → %s: %s', $name, $bucket, self::reason($e));
            }
        }

        foreach ($failed as $line) {
            $this->components->error($line);
        }

        if ($failed !== []) {
            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->components->warn(
                count($missing) . ' bucket(s) missing. Create them with your provider, or run this again with --create.'
            );

            return self::FAILURE;
        }

        $this->components->info('Every configured bucket exists.');

        return self::SUCCESS;
    }

    /**
     * What went wrong, in the words most likely to name it.
     *
     * An answer from the provider carries an error code (`AccessDenied`, `NoSuchBucket`) and a status,
     * and that pair is the whole story. A request that never arrived carries neither — no code, no
     * status — and its own message is then the only thing that says why: "cURL error 7: Failed to
     * connect to ... port 9000". Reporting `unknown error (HTTP -)` for that, which is what this did
     * first, describes the reporting rather than the fault.
     */
    private static function reason(AwsException $e): string
    {
        $code = $e->getAwsErrorCode();

        if (is_string($code) && $code !== '') {
            return sprintf('%s (HTTP %s)', $code, $e->getStatusCode() ?? '-');
        }

        // The innermost exception, because the SDK's own message says "AWS HTTP error:" and then stops
        // — what actually happened is in the exception it wrapped: "cURL error 7: Failed to connect to
        // 10.0.0.9 port 9000". The first line of that, since a stack of context follows it.
        $cause = $e;

        while ($cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }

        $first = strtok($cause->getMessage(), "\n");
        $message = is_string($first) ? trim($first) : '';

        return $message === '' ? 'no reason given' : Str::limit($message, 160);
    }
}
