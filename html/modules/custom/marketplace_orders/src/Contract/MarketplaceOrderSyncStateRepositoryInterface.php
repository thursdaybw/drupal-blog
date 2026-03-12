<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Contract;

/**
 * Port for marketplace order sync watermark state.
 */
interface MarketplaceOrderSyncStateRepositoryInterface {

  /**
   * Returns the last synced timestamp for marketplace.
   */
  public function getLastSyncedAt(string $marketplace): int;

  /**
   * Stores the last synced timestamp for marketplace.
   */
  public function setLastSyncedAt(string $marketplace, int $timestamp): void;

}
