<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Deterministic sync summary for command and logging surfaces.
 */
final class OrderSyncSummary {

  public function __construct(
    private readonly string $marketplace,
    private readonly int $sinceTimestamp,
    private readonly int $fetchedOrders,
    private readonly int $upsertedOrders,
    private readonly int $nextSinceTimestamp,
  ) {}

  public function getMarketplace(): string {
    return $this->marketplace;
  }

  public function getSinceTimestamp(): int {
    return $this->sinceTimestamp;
  }

  public function getFetchedOrders(): int {
    return $this->fetchedOrders;
  }

  public function getUpsertedOrders(): int {
    return $this->upsertedOrders;
  }

  public function getNextSinceTimestamp(): int {
    return $this->nextSinceTimestamp;
  }

}
