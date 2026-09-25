<?php

declare(strict_types=1);

namespace App\Domain\Banks\Models;

use Database\Factories\BankFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * One bank from the ECB's list — see the `ext_banks` migration for what each column is and why the
 * row is never deleted.
 *
 * Not scoped to a taxpayer: this is public reference data, the same for everyone.
 *
 * @property string $id
 * @property string $ecb_code
 * @property string|null $lei
 * @property string|null $bic
 * @property string $name
 * @property string $legal_name
 * @property string|null $parent_name
 * @property string|null $parent_lei
 * @property string $category
 * @property string $country_code
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $address
 * @property bool $active
 * @property \Illuminate\Support\Carbon $first_seen_on
 * @property \Illuminate\Support\Carbon $last_seen_on
 * @property \Illuminate\Support\Carbon|null $bic_checked_on
 */
#[UseFactory(BankFactory::class)]
final class Bank extends Model
{
    /** @use HasFactory<BankFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    protected $table = 'ext_banks';

    /** @var list<string> */
    protected $fillable = [
        'ecb_code',
        'lei',
        'bic',
        'name',
        'legal_name',
        'parent_name',
        'parent_lei',
        'category',
        'country_code',
        'city',
        'postal_code',
        'address',
        'active',
        'first_seen_on',
        'last_seen_on',
        'bic_checked_on',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
        'first_seen_on' => 'date',
        'last_seen_on' => 'date',
        'bic_checked_on' => 'date',
    ];

    /**
     * The bank's 4-letter code — the first four characters of its BIC, which is also the bank part
     * of a Romanian IBAN. Null where GLEIF maps no BIC to this bank.
     */
    public function code(): ?string
    {
        return $this->bic === null ? null : substr($this->bic, 0, 4);
    }
}
