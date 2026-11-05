<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Notice;
use Kern\Notifications\Services\NoticeTypes;
use Kern\Notifications\Tests\Fixtures\OrderShipped;

/** What the package refuses before anything is stored or sent. */
it('links a notice only to a path inside the application', function (string $url): void {
    expect(fn () => new Message('Title', 'Body', $url))->toThrow(InvalidArgumentException::class);
})->with([
    'another site' => ['https://evil.example/'],
    'a protocol-relative address' => ['//evil.example/'],
    'a backslash browsers read as a slash' => ['/\\evil.example'],
    'a script' => ['javascript:alert(1)'],
]);

it('refuses a kind registered twice, or a class that is not a kind', function (): void {
    $types = new NoticeTypes(new Container());
    $types->register(OrderShipped::class);

    expect(fn () => $types->register(OrderShipped::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $types->register(stdClass::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Notice(stdClass::class, []))->toThrow(InvalidArgumentException::class);
});
