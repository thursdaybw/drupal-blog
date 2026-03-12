<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Immutable order snapshot normalized from a marketplace payload.
 */
final class MarketplaceOrderSnapshot {

  /**
   * @param \Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot[] $lines
   */
  public function __construct(
    private readonly string $marketplace,
    private readonly string $externalOrderId,
    private readonly string $status,
    private readonly ?string $paymentStatus,
    private readonly ?string $fulfillmentStatus,
    private readonly ?int $orderedAt,
    private readonly ?string $buyerHandle,
    private readonly ?string $totalsJson,
    private readonly ?string $payloadHash,
    private readonly ?string $rawJson,
    private readonly array $lines,
  ) {}

  public function getMarketplace(): string {
    return $this->marketplace;
  }

  public function getExternalOrderId(): string {
    return $this->externalOrderId;
  }

  public function getStatus(): string {
    return $this->status;
  }

  public function getPaymentStatus(): ?string {
    return $this->paymentStatus;
  }

  public function getFulfillmentStatus(): ?string {
    return $this->fulfillmentStatus;
  }

  public function getOrderedAt(): ?int {
    return $this->orderedAt;
  }

  public function getBuyerHandle(): ?string {
    return $this->buyerHandle;
  }

  public function getTotalsJson(): ?string {
    return $this->totalsJson;
  }

  public function getPayloadHash(): ?string {
    return $this->payloadHash;
  }

  public function getRawJson(): ?string {
    return $this->rawJson;
  }

  /**
   * @return \Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot[]
   */
  public function getLines(): array {
    return $this->lines;
  }

}
