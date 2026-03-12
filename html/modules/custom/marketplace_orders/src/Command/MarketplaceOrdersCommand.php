<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Command;

use Drupal\marketplace_orders\Service\PickPackQueueQueryService;
use Drupal\marketplace_orders\Service\SyncMarketplaceOrdersSinceService;
use Drush\Commands\DrushCommands;

/**
 * Drush command surface for marketplace order synchronization.
 */
final class MarketplaceOrdersCommand extends DrushCommands {

  public function __construct(
    private readonly SyncMarketplaceOrdersSinceService $syncService,
  ) {
    parent::__construct();
  }

  /**
   * Synchronize marketplace orders into local order tables.
   *
   * @command marketplace-orders:sync
   * @aliases mosync
   *
   * @option marketplace
   *   Marketplace key, defaults to ebay.
   * @option since
   *   Optional UNIX timestamp or ISO-8601 timestamp.
   */
  public function sync(array $options = [
    'marketplace' => 'ebay',
    'since' => '',
  ]): void {
    $marketplace = trim((string) ($options['marketplace'] ?? 'ebay'));
    if ($marketplace === '') {
      $marketplace = 'ebay';
    }

    $sinceTimestamp = $this->parseSinceOption((string) ($options['since'] ?? ''));

    $summary = $this->syncService->sync($marketplace, $sinceTimestamp);

    $this->output()->writeln('Marketplace order sync complete.');
    $this->output()->writeln('- marketplace: ' . $summary->getMarketplace());
    $this->output()->writeln('- since_timestamp: ' . $summary->getSinceTimestamp());
    $this->output()->writeln('- fetched_orders: ' . $summary->getFetchedOrders());
    $this->output()->writeln('- upserted_orders: ' . $summary->getUpsertedOrders());
    $this->output()->writeln('- next_since_timestamp: ' . $summary->getNextSinceTimestamp());
    $this->output()->writeln('- next_since_iso: ' . gmdate('c', $summary->getNextSinceTimestamp()));
  }

  /**
   * Output pick-pack queue rows from local read model.
   *
   * @command marketplace-orders:pick-pack-queue
   * @aliases mopq
   *
   * @option marketplace
   *   Optional marketplace filter (for example: ebay).
   * @option limit
   *   Maximum rows to return. Defaults to 100.
   * @option offset
   *   Row offset for pagination. Defaults to 0.
   * @option all
   *   Include all rows and disable actionable-only filtering.
   * @option format
   *   Output format: tsv or json. Defaults to tsv.
   */
  public function pickPackQueue(array $options = [
    'marketplace' => '',
    'limit' => '100',
    'offset' => '0',
    'all' => FALSE,
    'format' => 'tsv',
  ]): void {
    $format = strtolower(trim((string) ($options['format'] ?? 'tsv')));
    if (!in_array($format, ['tsv', 'json'], TRUE)) {
      throw new \InvalidArgumentException('Invalid --format. Use tsv or json.');
    }

    $result = $this->pickPackQueueQueryService()->query([
      'marketplace' => trim((string) ($options['marketplace'] ?? '')),
      'limit' => (int) ($options['limit'] ?? 100),
      'offset' => (int) ($options['offset'] ?? 0),
      'actionable_only' => empty($options['all']),
    ]);

    if ($format === 'json') {
      $payload = [];
      foreach ($result->getRows() as $row) {
        $payload[] = [
          'order_id' => $row->getOrderId(),
          'marketplace' => $row->getMarketplace(),
          'external_order_id' => $row->getExternalOrderId(),
          'status' => $row->getStatus(),
          'payment_status' => $row->getPaymentStatus(),
          'fulfillment_status' => $row->getFulfillmentStatus(),
          'ordered_at' => $row->getOrderedAt(),
          'buyer_handle' => $row->getBuyerHandle(),
          'order_line_id' => $row->getOrderLineId(),
          'external_line_id' => $row->getExternalLineId(),
          'sku' => $row->getSku(),
          'quantity' => $row->getQuantity(),
          'line_title' => $row->getLineTitle(),
          'line_price' => $row->getLinePrice(),
          'listing_uuid' => $row->getListingUuid(),
          'listing_title' => $row->getListingTitle(),
          'storage_location' => $row->getStorageLocation(),
        ];
      }

      $this->output()->writeln((string) json_encode([
        'total_rows' => $result->getTotalRows(),
        'offset' => $result->getOffset(),
        'limit' => $result->getLimit(),
        'rows' => $payload,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return;
    }

    $this->output()->writeln(sprintf(
      '# total_rows=%d offset=%d limit=%d',
      $result->getTotalRows(),
      $result->getOffset(),
      $result->getLimit(),
    ));

    $this->output()->writeln(implode("\t", [
      'order_id',
      'marketplace',
      'external_order_id',
      'status',
      'payment_status',
      'fulfillment_status',
      'ordered_at',
      'buyer_handle',
      'order_line_id',
      'external_line_id',
      'sku',
      'quantity',
      'line_title',
      'line_price',
      'listing_uuid',
      'listing_title',
      'storage_location',
    ]));

    foreach ($result->getRows() as $row) {
      $this->output()->writeln(implode("\t", [
        (string) $row->getOrderId(),
        $this->sanitizeTsvCell($row->getMarketplace()),
        $this->sanitizeTsvCell($row->getExternalOrderId()),
        $this->sanitizeTsvCell($row->getStatus()),
        $this->sanitizeTsvCell($row->getPaymentStatus() ?? ''),
        $this->sanitizeTsvCell($row->getFulfillmentStatus() ?? ''),
        (string) ($row->getOrderedAt() ?? 0),
        $this->sanitizeTsvCell($row->getBuyerHandle() ?? ''),
        (string) $row->getOrderLineId(),
        $this->sanitizeTsvCell($row->getExternalLineId()),
        $this->sanitizeTsvCell($row->getSku() ?? ''),
        (string) $row->getQuantity(),
        $this->sanitizeTsvCell($row->getLineTitle() ?? ''),
        $this->sanitizeTsvCell($row->getLinePrice() ?? ''),
        $this->sanitizeTsvCell($row->getListingUuid() ?? ''),
        $this->sanitizeTsvCell($row->getListingTitle() ?? ''),
        $this->sanitizeTsvCell($row->getStorageLocation() ?? ''),
      ]));
    }
  }

  private function parseSinceOption(string $value): ?int {
    $trimmedValue = trim($value);
    if ($trimmedValue === '') {
      return NULL;
    }

    if (ctype_digit($trimmedValue)) {
      return (int) $trimmedValue;
    }

    $parsed = strtotime($trimmedValue);
    if ($parsed === FALSE) {
      throw new \InvalidArgumentException(sprintf(
        'Invalid --since value "%s". Use UNIX timestamp or ISO-8601.',
        $trimmedValue
      ));
    }

    return $parsed;
  }

  private function sanitizeTsvCell(string $value): string {
    return str_replace(["\t", "\n", "\r"], ' ', $value);
  }

  private function pickPackQueueQueryService(): PickPackQueueQueryService {
    return \Drupal::service(PickPackQueueQueryService::class);
  }

}
