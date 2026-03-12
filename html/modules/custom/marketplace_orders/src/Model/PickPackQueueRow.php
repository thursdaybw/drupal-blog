<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Immutable row projection for pick-pack queue surfaces.
 */
final class PickPackQueueRow {

  public function __construct(
    private readonly int $orderId,
    private readonly string $marketplace,
    private readonly string $externalOrderId,
    private readonly string $status,
    private readonly ?string $paymentStatus,
    private readonly ?string $fulfillmentStatus,
    private readonly ?int $orderedAt,
    private readonly ?string $buyerHandle,
    private readonly int $orderLineId,
    private readonly string $externalLineId,
    private readonly ?string $sku,
    private readonly int $quantity,
    private readonly ?string $lineTitle,
    private readonly ?string $linePrice,
    private readonly ?string $listingUuid,
    private readonly ?string $listingTitle,
    private readonly ?string $storageLocation,
  ) {}

  public function getOrderId(): int { return $this->orderId; }
  public function getMarketplace(): string { return $this->marketplace; }
  public function getExternalOrderId(): string { return $this->externalOrderId; }
  public function getStatus(): string { return $this->status; }
  public function getPaymentStatus(): ?string { return $this->paymentStatus; }
  public function getFulfillmentStatus(): ?string { return $this->fulfillmentStatus; }
  public function getOrderedAt(): ?int { return $this->orderedAt; }
  public function getBuyerHandle(): ?string { return $this->buyerHandle; }
  public function getOrderLineId(): int { return $this->orderLineId; }
  public function getExternalLineId(): string { return $this->externalLineId; }
  public function getSku(): ?string { return $this->sku; }
  public function getQuantity(): int { return $this->quantity; }
  public function getLineTitle(): ?string { return $this->lineTitle; }
  public function getLinePrice(): ?string { return $this->linePrice; }
  public function getListingUuid(): ?string { return $this->listingUuid; }
  public function getListingTitle(): ?string { return $this->listingTitle; }
  public function getStorageLocation(): ?string { return $this->storageLocation; }

}
