<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Jobs\ReadTreasuryOrder;
use Kern\Investments\Models\TreasuryOrder;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;

/**
 * Orders already in the laws library that were never read for their issues — added from the laws screen
 * before this package was installed, say, when nothing listened — queued to be read now. An order this
 * package has read is not one of them, nor one whose reading as a law has not finished.
 */
final readonly class UnreadOrders
{
    public function __construct(
        private InvestmentsConfig $config,
    ) {
    }

    public function queue(): string
    {
        $queued = 0;
        $orders = SourceDocument::query()
            ->where('document_type', $this->config->ordersActType)
            ->whereNotIn('id', TreasuryOrder::query()->select('source_document_id'))
            ->whereIn('id', SourceDocumentVersion::query()->select('source_document_id'))
            ->cursor();

        foreach ($orders as $order) {
            foreach ($this->config->programmes as $programme) {
                if ($programme->isTitled($order->title ?? '')) {
                    ReadTreasuryOrder::dispatch($order->id, $programme->programme)->onQueue($this->config->queue);
                    $queued++;

                    break;
                }
            }
        }

        return "orders: {$queued} already laws, never read for their issues, queued to be read";
    }
}
