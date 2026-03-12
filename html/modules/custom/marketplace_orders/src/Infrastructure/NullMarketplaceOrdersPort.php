<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure;

use Drupal\marketplace_orders\Contract\MarketplaceOrdersPortInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrdersBatch;

/**
 * Safe default adapter that returns no external orders.
 */
final class NullMarketplaceOrdersPort implements MarketplaceOrdersPortInterface {

  /**
   * {@inheritdoc}
   */
  public function fetchOrdersSince(string $marketplace, int $sinceTimestamp, ?string $cursor = NULL): MarketplaceOrdersBatch {
    return new MarketplaceOrdersBatch(orders: [], nextCursor: NULL);
  }

}
