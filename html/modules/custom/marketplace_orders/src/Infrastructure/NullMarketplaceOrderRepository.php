<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure;

use Drupal\marketplace_orders\Contract\MarketplaceOrderRepositoryInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot;

/**
 * Safe default repository that performs no writes.
 */
final class NullMarketplaceOrderRepository implements MarketplaceOrderRepositoryInterface {

  /**
   * {@inheritdoc}
   */
  public function upsertOrder(MarketplaceOrderSnapshot $order): int {
    return 0;
  }

}
