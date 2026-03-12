<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure\Ebay;

use Drupal\ebay_infrastructure\Service\EbayFulfillmentOrdersClient;
use Drupal\marketplace_orders\Contract\MarketplaceOrdersPortInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrdersBatch;

/**
 * eBay implementation of the marketplace orders port.
 */
final class EbayMarketplaceOrdersPort implements MarketplaceOrdersPortInterface {

  private const MARKETPLACE_KEY = 'ebay';
  private const PAGE_LIMIT = 50;

  public function __construct(
    private readonly EbayFulfillmentOrdersClient $fulfillmentOrdersClient,
    private readonly EbayOrderPayloadMapper $payloadMapper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fetchOrdersSince(string $marketplace, int $sinceTimestamp, ?string $cursor = NULL): MarketplaceOrdersBatch {
    $this->assertMarketplaceSupported($marketplace);

    $offset = $this->parseCursorToOffset($cursor);
    $response = $this->fulfillmentOrdersClient->listOrders(
      limit: self::PAGE_LIMIT,
      offset: $offset,
      createdSinceTimestamp: $this->normalizeSinceTimestamp($sinceTimestamp),
    );

    $orders = $this->mapOrderSnapshots($response);
    $nextCursor = $this->deriveNextCursor($response, $offset);

    return new MarketplaceOrdersBatch($orders, $nextCursor);
  }

  private function assertMarketplaceSupported(string $marketplace): void {
    if ($marketplace === self::MARKETPLACE_KEY) {
      return;
    }

    throw new \InvalidArgumentException(sprintf(
      'Unsupported marketplace "%s" for eBay orders port.',
      $marketplace
    ));
  }

  private function parseCursorToOffset(?string $cursor): int {
    if ($cursor === NULL || $cursor === '') {
      return 0;
    }

    if (!ctype_digit($cursor)) {
      return 0;
    }

    return (int) $cursor;
  }

  private function normalizeSinceTimestamp(int $sinceTimestamp): ?int {
    if ($sinceTimestamp <= 0) {
      return NULL;
    }

    return $sinceTimestamp;
  }

  /**
   * @param array<string, mixed> $response
   *
   * @return array<int, \Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot>
   */
  private function mapOrderSnapshots(array $response): array {
    $ordersPayload = $response['orders'] ?? [];
    if (!is_array($ordersPayload)) {
      return [];
    }

    $orderSnapshots = [];

    foreach ($ordersPayload as $orderPayload) {
      if (!is_array($orderPayload)) {
        continue;
      }

      $orderSnapshots[] = $this->payloadMapper->mapPayload($orderPayload);
    }

    return $orderSnapshots;
  }

  /**
   * @param array<string, mixed> $response
   */
  private function deriveNextCursor(array $response, int $offset): ?string {
    $ordersPayload = $response['orders'] ?? [];
    if (!is_array($ordersPayload)) {
      return NULL;
    }

    $fetchedCount = count($ordersPayload);
    if ($fetchedCount === 0) {
      return NULL;
    }

    $total = $this->extractTotal($response, $offset, $fetchedCount);
    $nextOffset = $offset + $fetchedCount;

    if ($nextOffset >= $total) {
      return NULL;
    }

    return (string) $nextOffset;
  }

  /**
   * @param array<string, mixed> $response
   */
  private function extractTotal(array $response, int $offset, int $fetchedCount): int {
    $total = $response['total'] ?? NULL;
    if (is_numeric($total)) {
      return (int) $total;
    }

    return $offset + $fetchedCount;
  }

}
