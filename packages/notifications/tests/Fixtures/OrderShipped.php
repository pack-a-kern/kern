<?php

declare(strict_types=1);

namespace Kern\Notifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\Contracts\NoticeType;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Enums\Level;
use Kern\Notifications\Notice;

/**
 * The README's example kind, as a test fixture: an order left the warehouse, or could not.
 *
 * The stored notice holds the order's number and a code; the words, including the parcel's carrier,
 * are written when the notice is shown. An order no longer in `Orders` has nothing to say.
 */
final readonly class OrderShipped implements NoticeType
{
    public function __construct(private Orders $orders)
    {
    }

    public static function key(): string
    {
        return 'order-shipped';
    }

    public static function about(int $order, bool $delayed = false): Notice
    {
        return new Notice(self::class, ['order' => $order, 'delayed' => $delayed]);
    }

    public function label(string $locale): string
    {
        return $locale === 'ro' ? 'Comenzi expediate' : 'Orders shipped';
    }

    public function hint(string $locale): string
    {
        return $locale === 'ro' ? 'Când o comandă pleacă din depozit.' : 'When an order leaves the warehouse.';
    }

    public function render(array $notices, Model $notifiable, string $locale): array
    {
        $messages = [];

        foreach ($notices as $id => $data) {
            $order = is_int($data['order'] ?? null) ? $data['order'] : 0;
            $carrier = $this->orders->carrierOf($order);

            if ($carrier === null) {
                continue;
            }

            $delayed = ($data['delayed'] ?? false) === true;
            $messages[$id] = new Message(
                title: $locale === 'ro' ? "Comanda #{$order} a plecat" : "Order #{$order} has shipped",
                body: $delayed ? "Delayed — {$carrier}" : "With {$carrier}",
                url: "/orders/{$order}",
                level: $delayed ? Level::Error : Level::Info,
                tag: "order-{$order}",
            );
        }

        return $messages;
    }
}
