<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Contract;

use Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot;

/**
 * Port for persisting normalized marketplace orders.
 */
interface MarketplaceOrderRepositoryInterface {

  /**
   * Upserts an order and its lines idempotently.
   *
   * @return int
   *   Local order row ID.
   */
  public function upsertOrder(MarketplaceOrderSnapshot $order): int;

}
