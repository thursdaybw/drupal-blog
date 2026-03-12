<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\marketplace_orders\Contract\MarketplaceOrderSyncStateRepositoryInterface;

/**
 * Database-backed watermark repository for marketplace order sync.
 */
final class DatabaseMarketplaceOrderSyncStateRepository implements MarketplaceOrderSyncStateRepositoryInterface {

  public function __construct(
    private readonly Connection $connection,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getLastSyncedAt(string $marketplace): int {
    $value = $this->connection
      ->select('marketplace_order_sync_state', 's')
      ->fields('s', ['last_synced_at'])
      ->condition('marketplace', $marketplace)
      ->execute()
      ->fetchField();

    if ($value === FALSE || $value === NULL) {
      return 0;
    }

    return (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setLastSyncedAt(string $marketplace, int $timestamp): void {
    $this->connection
      ->merge('marketplace_order_sync_state')
      ->key('marketplace', $marketplace)
      ->fields([
        'last_synced_at' => $timestamp,
        'changed' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

}
