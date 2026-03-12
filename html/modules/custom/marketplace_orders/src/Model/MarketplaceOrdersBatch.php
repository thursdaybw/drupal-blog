<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * One page of normalized orders returned by a marketplace adapter.
 */
final class MarketplaceOrdersBatch {

  /**
   * @param \Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot[] $orders
   */
  public function __construct(
    private readonly array $orders,
    private readonly ?string $nextCursor,
  ) {}

  /**
   * @return \Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot[]
   */
  public function getOrders(): array {
    return $this->orders;
  }

  public function getNextCursor(): ?string {
    return $this->nextCursor;
  }

  public function hasNextCursor(): bool {
    return $this->nextCursor !== NULL && $this->nextCursor !== '';
  }

}
