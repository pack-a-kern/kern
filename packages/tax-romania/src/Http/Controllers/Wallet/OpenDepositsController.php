<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount;
use Kern\TaxRomania\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;

/**
 * `POST /api/v1/subjects/{subject}/wallet/deposits` — open several term deposits at once.
 *
 * Exactly the account form, many times. Each deposit is checked against {@see DepositFields}, the
 * same rules a single deposit on the form is checked against, and opened by the same
 * {@see OpenFinancialAccount} — so each gets its interest schedule and its audit event, and a
 * deposit added from a CSV cannot be anything a deposit added by hand could not be.
 *
 * ## All of them, or none
 *
 * Every deposit is validated before the first is opened, and then all are opened in one
 * transaction. A file with one bad line opens nothing and says which line: half a file imported is a
 * file a person can no longer simply correct and send again, because the half that went in would go
 * in twice.
 *
 * ## What it trusts
 *
 * Nothing the browser read: this receives the deposits as the form would send them, and checks them
 * as the form's own endpoint does. The subject comes from `TenantContext`, never from the body.
 */
final class OpenDepositsController
{
    public function __invoke(
        Request $request,
        ConfigRepository $config,
        ValidatorFactory $validators,
        ConnectionInterface $database,
        OpenFinancialAccount $open,
        DepositFields $fields,
        TenantContext $tenant,
    ): JsonResponse {
        $request->validate([
            'deposits' => ['required', 'array', 'list', 'min:1', 'max:' . $config->integer('wallet.deposit.import.max_rows')],
            'deposits.*' => ['array'],
        ]);

        $rules = DepositFields::rules();

        foreach (DepositFields::required() as $field) {
            array_unshift($rules[$field], 'required');
        }

        /** @var list<array<string, mixed>> $deposits */
        $deposits = (array) $request->input('deposits');
        $valid = [];
        $errors = [];

        // Each on its own, so a rule that names another field (`after:starts_at`) reads this deposit's,
        // and the messages come back keyed by the deposit they belong to.
        foreach ($deposits as $index => $deposit) {
            $validator = $validators->make($deposit, $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors['deposits.' . $index . '.' . $field] = $messages;
                }

                continue;
            }

            $valid[] = $validator->validated();
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $subjectId = $tenant->currentTenantId();
        $actorId = self::actorId($request);

        $database->transaction(function () use ($valid, $open, $fields, $subjectId, $actorId): void {
            foreach ($valid as $deposit) {
                $open(new OpenFinancialAccountInput(
                    taxSubjectId: $subjectId,
                    kind: AccountKind::TERM_DEPOSIT,
                    displayName: $fields->displayName($deposit),
                    currencyCode: DepositFields::nullableString($deposit, 'currency_code'),
                    depositTerms: $fields->terms($deposit),
                    actorId: $actorId,
                ));
            }
        });

        return response()->json(['opened' => count($valid)], 201);
    }

    private static function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
