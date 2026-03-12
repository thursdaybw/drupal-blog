<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Service;

use Drupal\marketplace_orders\Contract\MarketplaceOrderRepositoryInterface;
use Drupal\marketplace_orders\Contract\MarketplaceOrdersPortInterface;
use Drupal\marketplace_orders\Contract\MarketplaceOrderSyncStateRepositoryInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot;
use Drupal\marketplace_orders\Model\OrderSyncSummary;

/**
 * Application use case: synchronize marketplace orders since watermark.
 */
final class SyncMarketplaceOrdersSinceService {

  public function __construct(
    private readonly MarketplaceOrdersPortInterface $marketplaceOrdersPort,
    private readonly MarketplaceOrderRepositoryInterface $orderRepository,
    private readonly MarketplaceOrderSyncStateRepositoryInterface $syncStateRepository,
  ) {}

  /**
   * Pulls marketplace orders since stored watermark and upserts deterministically.
   */
  public function sync(string $marketplace, ?int $sinceTimestamp = NULL): OrderSyncSummary {
    $effectiveSinceTimestamp = $this->resolveEffectiveSinceTimestamp($marketplace, $sinceTimestamp);

    $fetchedOrders = 0;
    $upsertedOrders = 0;
    $maxSeenOrderedAt = $effectiveSinceTimestamp;
    $cursor = NULL;

    do {
      $batch = $this->marketplaceOrdersPort->fetchOrdersSince($marketplace, $effectiveSinceTimestamp, $cursor);

      foreach ($batch->getOrders() as $orderSnapshot) {
        $this->orderRepository->upsertOrder($orderSnapshot);
        $fetchedOrders++;
        $upsertedOrders++;
        $maxSeenOrderedAt = $this->deriveMaxSeenOrderedAt($maxSeenOrderedAt, $orderSnapshot);
      }

      $cursor = $batch->getNextCursor();
    } while ($cursor !== NULL && $cursor !== '');

    $this->syncStateRepository->setLastSyncedAt($marketplace, $maxSeenOrderedAt);

    return new OrderSyncSummary(
      marketplace: $marketplace,
      sinceTimestamp: $effectiveSinceTimestamp,
      fetchedOrders: $fetchedOrders,
      upsertedOrders: $upsertedOrders,
      nextSinceTimestamp: $maxSeenOrderedAt,
    );
  }

  private function resolveEffectiveSinceTimestamp(string $marketplace, ?int $sinceTimestamp): int {
    if ($sinceTimestamp !== NULL) {
      return $sinceTimestamp;
    }

    return $this->syncStateRepository->getLastSyncedAt($marketplace);
  }

  private function deriveMaxSeenOrderedAt(int $currentMax, MarketplaceOrderSnapshot $orderSnapshot): int {
    $orderedAt = $orderSnapshot->getOrderedAt();

    if ($orderedAt === NULL) {
      return $currentMax;
    }

    if ($orderedAt <= $currentMax) {
      return $currentMax;
    }

    return $orderedAt;
  }

}
