<?php

declare(strict_types=1);

use Kern\Audit\Support\CanonicalJson;

test('key order in the input array never affects the encoded bytes', function (): void {
    $insertedOneWay = [
        'event_type' => 'DocumentUploaded',
        'chain_key' => 'system',
        'sequence' => 3,
        'subject_id' => null,
    ];

    $insertedTheOtherWay = [
        'subject_id' => null,
        'sequence' => 3,
        'event_type' => 'DocumentUploaded',
        'chain_key' => 'system',
    ];

    expect(CanonicalJson::encode($insertedOneWay))
        ->toBe(CanonicalJson::encode($insertedTheOtherWay));
});

test('nested associative arrays are sorted recursively at every level', function (): void {
    $a = ['outer' => ['z' => 1, 'a' => 2], 'top' => 1];
    $b = ['top' => 1, 'outer' => ['a' => 2, 'z' => 1]];

    expect(CanonicalJson::encode($a))->toBe(CanonicalJson::encode($b));
});

test('the exact encoded bytes are pinned, not just self-consistent', function (): void {
    $payload = [
        'id' => '01930000-0000-7000-8000-000000000001',
        'chain_key' => 'system',
        'sequence' => 1,
        'subject_id' => null,
        'actor_id' => null,
        'event_type' => 'DocumentUploaded',
        'resource_type' => 'Document',
        'resource_id' => 'doc-1',
        'occurred_at' => '2026-09-08T10:00:00.000000Z',
        'payload_hash' => str_repeat('a', 64),
        'previous_hash' => str_repeat('0', 64),
    ];

    $expected = '{'
        . '"actor_id":null,'
        . '"chain_key":"system",'
        . '"event_type":"DocumentUploaded",'
        . '"id":"01930000-0000-7000-8000-000000000001",'
        . '"occurred_at":"2026-09-08T10:00:00.000000Z",'
        . '"payload_hash":"' . str_repeat('a', 64) . '",'
        . '"previous_hash":"' . str_repeat('0', 64) . '",'
        . '"resource_id":"doc-1",'
        . '"resource_type":"Document",'
        . '"sequence":1,'
        . '"subject_id":null'
        . '}';

    expect(CanonicalJson::encode($payload))->toBe($expected);
});

test('list arrays keep their given order and are not treated as objects', function (): void {
    $data = ['tags' => ['b', 'a', 'c']];

    expect(CanonicalJson::encode($data))->toBe('{"tags":["b","a","c"]}');
});

test('non-ASCII characters (Romanian diacritics) are escaped consistently', function (): void {
    $data = ['note' => 'Venituri din dobânzi și câștiguri'];

    $encoded = CanonicalJson::encode($data);

    expect($encoded)->not->toContain('â')
        ->and($encoded)->toContain('\u');
});

test('null values are preserved explicitly, never dropped', function (): void {
    $encoded = CanonicalJson::encode(['subject_id' => null]);

    expect($encoded)->toBe('{"subject_id":null}');
});

test('a float anywhere in the structure is rejected', function (): void {
    CanonicalJson::encode(['amount' => 12.5]);
})->throws(InvalidArgumentException::class);

test('a non-string array key is rejected', function (): void {
    CanonicalJson::encode([0 => 'a', 'x' => 'b']);
})->throws(InvalidArgumentException::class);
