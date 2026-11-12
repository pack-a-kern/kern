<?php

declare(strict_types=1);

namespace Kern\Platform\Tests\Unit\Routing\PageSizesTest;

use Illuminate\Config\Repository;
use Kern\Platform\Routing\PageSizes;

function sizes(mixed $sizes, mixed $default): PageSizes
{
    return PageSizes::fromConfig(new Repository(['kern' => ['paging' => ['sizes' => $sizes, 'default' => $default]]]));
}

it('reads the sizes and the default from configuration', function (): void {
    $paging = sizes('10, 25,50', '25');

    expect($paging->toArray())->toBe(['sizes' => [10, 25, 50], 'default' => 25])
        ->and($paging->of('50'))->toBe(50)
        ->and($paging->of(null))->toBe(25);
});

it('fails, naming the variable, when a value is missing or malformed', function (mixed $sizes, mixed $default, string $variable): void {
    expect(fn (): PageSizes => sizes($sizes, $default))->toThrow(\RuntimeException::class, $variable);
})->with([
    'no sizes' => [null, '25', 'KERN_PAGE_SIZES'],
    'not a number' => ['10,ten', '10', 'KERN_PAGE_SIZES'],
    'zero' => ['0,10', '10', 'KERN_PAGE_SIZES'],
    'not ascending' => ['25,10', '10', 'KERN_PAGE_SIZES'],
    'no default' => ['10,25', null, 'KERN_PAGE_SIZE_DEFAULT'],
    'default not offered' => ['10,25', '20', 'KERN_PAGE_SIZE_DEFAULT'],
]);
