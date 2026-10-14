<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Identity;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Access\Services\AccessCatalogue;
use Kern\Platform\Identity\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Opens the first administrator account, with a password whoever runs this chooses.
 *
 * ## Why this exists beside the three commands that nearly do it
 *
 * `kern:access:grant` says in its own docblock that it "is how the *first* administrator comes into
 * existence" — and it is, once an account exists to grant a role to. Nothing creates that account.
 * Registration does, through the HTTP layer, which on a fresh installation means someone has to
 * find the sign-up screen, get past email verification with mail not yet configured, and only then
 * reach a shell to grant themselves a role. That is three steps across two trust boundaries to
 * reach a state an installer should hand over finished.
 *
 * So this command creates the account, marks the address verified and grants the role, and the
 * other three keep their jobs: `kern:access:grant` for every role after the first,
 * `kern:identity:verify-email` for the way back in when mail breaks, and
 * {@see \Kern\Platform\Identity\Actions\CreateUserAccount} for an administrator opening an account
 * for somebody else from the UI.
 *
 * It is deliberately not that action. The action generates the password, because an administrator
 * typing one types the same one for everybody — right for an account handed to a third person,
 * wrong here, where the operator is creating their own account and has to be able to sign in with
 * it afterwards. The action also requires an `actorId`, and there is nobody signed in.
 *
 * ## Why the password is never an argument
 *
 * There is no `--password` option. Arguments are visible in `ps`, in the shell's history and in
 * `docker inspect` for anything run through compose, and a password that reaches any of those is
 * not a password any more. By default this prompts for it twice; `--stdin` reads one line from
 * standard input instead, which is how the installer passes it:
 *
 *     printf '%s\n' "$password" | php artisan kern:admin:create --stdin admin@example.ro
 *
 * `--stdin` is a flag rather than something worked out from the terminal, because every way of
 * working it out is wrong somewhere. `$this->input->isInteractive()` reflects `--no-interaction`
 * alone and stays true for a piped stdin, so asking it sent the installer down the prompt path,
 * where `secret()` read the password as the first answer and end-of-input as the second and
 * reported that the two did not match. Asking `stream_isatty(STDIN)` instead fixed that and broke
 * the tests, which have no terminal either and so never reached the prompt they were answering.
 * A caller saying which it wants cannot be wrong about it.
 *
 * The address is an argument, because an address is not a secret.
 *
 * ## Why the address is marked verified
 *
 * `identity.email_verification_required` holds unverified accounts at a screen whose only exit is a
 * code sent by mail, and an installation being set up usually has no working mail yet — so an
 * unverified first administrator is an installation nobody can enter. `--unverified` is there for
 * whoever wants the account to go through that flow for real.
 */
final class CreateAdministratorCommand extends Command
{
    protected $signature = 'kern:admin:create {email : The address to open the account for}
                            {--role=SUPER_ADMIN : A role from config/access.php}
                            {--stdin : Read the password from standard input instead of prompting}
                            {--unverified : Leave the address unverified, so the account must verify by mail}';

    protected $description = 'Open the first administrator account — creates the user, verifies the address and grants the role.';

    public function handle(AccessCatalogue $catalogue, AuditChainService $audit): int
    {
        $email = trim((string) $this->argument('email'));
        $role = (string) $this->option('role');

        if (! in_array($role, $catalogue->roles(), true)) {
            $this->error(sprintf('Unknown role [%s]. Declared roles: %s.', $role, implode(', ', $catalogue->roles())));

            return self::FAILURE;
        }

        // The roles have to exist as rows before one can be assigned. Checked rather than created:
        // `kern:access:sync` is the one thing that writes the catalogue, and silently creating a
        // single role here would leave an installation holding one role and none of its permissions.
        if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            $this->error(sprintf('The role [%s] is not in the database yet. Run `php artisan kern:access:sync` first.', $role));

            return self::FAILURE;
        }

        if (! $this->validateEmail($email)) {
            return self::FAILURE;
        }

        // A blind-index lookup: no row is decrypted to find one (BLUEPRINT.md §4.4).
        if (User::query()->whereEmail($email)->exists()) {
            $this->error('An account already exists for that address. Use `kern:access:grant` to give it a role.');

            return self::FAILURE;
        }

        $password = $this->readPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $verified = ! (bool) $this->option('unverified');

        $user = DB::transaction(function () use ($email, $password, $role, $verified, $audit): User {
            $user = User::createWithEmail($email, Hash::make($password));

            if ($verified) {
                $user->email_verified_at = Carbon::now('UTC');
                $user->save();
            }

            $user->assignRole($role);

            // `actorId: null` and a CONSOLE channel, the convention
            // {@see VerifyUserEmailCommand} sets: nobody signed in to do this, and naming a user as
            // the actor would claim somebody did. The role is recorded; the password is not, and
            // never will be — an audit entry carrying it would be a permanent copy of a live
            // credential.
            $audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: null,
                eventType: 'AdministratorCreatedFromConsole',
                resourceType: 'User',
                resourceId: $user->id,
                payloadHash: $audit->hashPayload([
                    'user_id' => $user->id,
                    'role' => $role,
                    'verified' => $verified,
                    'channel' => 'CONSOLE',
                ]),
            ));

            return $user;
        });

        $this->info(sprintf('Opened %s with the role %s.', $email, $role));

        if (! $verified) {
            $this->warn('The address is not verified. If identity.email_verification_required is on, this account cannot sign in until it is — `kern:identity:verify-email` is the way through without mail.');
        }

        $this->line(sprintf('  id %s', $user->id));

        return self::SUCCESS;
    }

    private function validateEmail(string $email): bool
    {
        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return false;
        }

        return true;
    }

    /**
     * The password, from a prompt or from standard input, held to the rule the application's own
     * password-change screen uses.
     */
    private function readPassword(): ?string
    {
        // `->uncompromised()` asks Have I Been Pwned over the network. Laravel's verifier treats an
        // unreachable API as "not compromised", so an installation without egress is held to the
        // length rule alone rather than being unable to create an account at all — the same
        // behaviour every other password in this application gets, which is the point of reusing
        // the rule rather than writing a second one here.
        $rules = ['password' => ['required', 'string', Password::min(12)->uncompromised()]];

        if ((bool) $this->option('stdin')) {
            $password = fgets(STDIN);

            if ($password === false) {
                $this->error('No password on standard input. Pipe one in, or run this on a terminal to be prompted.');

                return null;
            }

            // One line, newline stripped. Trailing whitespace is removed because a here-doc or a
            // `printf` adds it; leading whitespace is kept, since it may be part of the password.
            $password = rtrim($password, "\r\n");

            $validator = Validator::make(['password' => $password], $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                return null;
            }

            return $password;
        }

        // `secret()` is typed `mixed`, and level 9 refuses a cast from it. An empty answer reads as
        // null, which the length rule below rejects anyway — this only decides how it gets there.
        $first = $this->secret('A password for this account (at least 12 characters)');
        $second = $this->secret('The same password again');

        $password = is_string($first) ? $first : '';
        $again = is_string($second) ? $second : '';

        if ($password !== $again) {
            $this->error('The two passwords are not the same.');

            return null;
        }

        $validator = Validator::make(['password' => $password], $rules);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $password;
    }
}
