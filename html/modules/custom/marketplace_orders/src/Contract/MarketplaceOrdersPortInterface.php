<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Contract;

use Drupal\marketplace_orders\Model\MarketplaceOrdersBatch;

/**
 * Port for reading orders from an external marketplace.
 */
interface MarketplaceOrdersPortInterface {

  /**
   * Fetches one batch of orders changed since a boundary.
   *
   * @param string $marketplace
   *   Marketplace key, for example "ebay".
   * @param int $sinceTimestamp
   *   Inclusive lower bound UNIX timestamp.
   * @param string|null $cursor
   *   Optional adapter cursor for pagination.
   *
   * @return \Drupal\marketplace_orders\Model\MarketplaceOrdersBatch
   *   Batch payload and pagination cursor.
   */
  public function fetchOrdersSince(string $marketplace, int $sinceTimestamp, ?string $cursor = NULL): MarketplaceOrdersBatch;

}
