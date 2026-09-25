<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;
use Kern\Platform\Tenancy\Exceptions\UnauthorizedTenantAccessException;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\Platform\Tenancy\Tenancy;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;
use Kern\Platform\Testing\NobodyIsAMember;

/**
 * The tenancy mechanism with the tenant called something else entirely.
 *
 * This is the test the whole extraction was for. In the product a tenant is a taxpayer and the column
 * is `tax_subject_id`; here it is a workspace and the column is `workspace_id`, configured and nothing
 * more. If any of the platform had the product's word written into it, every assertion below would
 * fail — and one of them did, before `Tenancy::column()` existed.
 */
beforeEach(function (): void {
    config()->set('tenancy.column', 'workspace_id');
    config()->set('tenancy.table', 'workspaces');

    Schema::create('workspaces', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
    });

    Schema::create('notes', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        // Declared by the platform, under the configured name, with a foreign key only because the
        // owner's table happens to exist — the same call a packaged migration makes.
        Tenancy::addColumn($table);
        $table->string('body');
    });
});

it('declares the column the configuration names, and points it at the owner', function (): void {
    expect(Schema::hasColumn('notes', 'workspace_id'))->toBeTrue()
        ->and(Schema::hasColumn('notes', 'tax_subject_id'))->toBeFalse();

    $keys = DB::select(
        "SELECT confrelid::regclass::text AS target FROM pg_constraint WHERE conrelid = 'notes'::regclass AND contype = 'f'"
    );

    expect(array_map(static fn (object $k): string => (string) $k->target, $keys))->toBe(['workspaces']);
});

it('restricts every read to the bound tenant and stamps every write with it', function (): void {
    $mine = workspace('Mine');
    $theirs = workspace('Theirs');

    activate($theirs);
    Note::create(['body' => 'not yours']);

    activate($mine);
    Note::create(['body' => 'mine']);

    // Stamped without being told: the trait filled the column from the bound context.
    expect(Note::query()->pluck('body')->all())->toBe(['mine'])
        ->and(Note::query()->sole()->getAttribute('workspace_id'))->toBe($mine)
        // And the other row is reachable only by naming what is being skipped.
        ->and(Note::withoutGlobalScope(TenantScope::class)->count())->toBe(2);
});

it('refuses to read anything at all when no tenant is bound', function (): void {
    workspace('Mine');

    expect(fn (): int => Note::query()->count())->toThrow(NoTenantContextException::class);
});

it('honours the application\'s refusal', function (): void {
    app()->bind(Kern\Platform\Tenancy\Contracts\TenantGate::class, NobodyIsAMember::class);

    $workspace = workspace('Mine');

    expect(fn () => app(TenantContext::class)->authorizeAndActivate((string) Str::uuid7(), $workspace))
        ->toThrow(UnauthorizedTenantAccessException::class);
});

function workspace(string $name): string
{
    $id = (string) Str::uuid7();
    DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);

    return $id;
}

function activate(string $workspaceId): void
{
    app(TenantContext::class)->authorizeAndActivate((string) Str::uuid7(), $workspaceId);
}

/**
 * A model belonging to a tenant, defined here because the platform ships none — every table that
 * carries the column belongs to some other package.
 */
final class Note extends Model
{
    use ScopedToTenant;

    protected $table = 'notes';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'body'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (Note $note): void {
            $note->id ??= (string) Str::uuid7();
        });
    }
}
