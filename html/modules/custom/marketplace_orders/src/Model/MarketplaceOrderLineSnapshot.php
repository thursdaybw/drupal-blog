<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Immutable line snapshot normalized from a marketplace payload.
 */
final class MarketplaceOrderLineSnapshot {

  public function __construct(
    private readonly string $externalLineId,
    private readonly ?string $sku,
    private readonly int $quantity,
    private readonly ?string $titleSnapshot,
    private readonly ?string $priceSnapshot,
    private readonly ?string $listingUuid,
    private readonly ?string $rawJson,
  ) {}

  public function getExternalLineId(): string {
    return $this->externalLineId;
  }

  public function getSku(): ?string {
    return $this->sku;
  }

  public function getQuantity(): int {
    return $this->quantity;
  }

  public function getTitleSnapshot(): ?string {
    return $this->titleSnapshot;
  }

  public function getPriceSnapshot(): ?string {
    return $this->priceSnapshot;
  }

  public function getListingUuid(): ?string {
    return $this->listingUuid;
  }

  public function getRawJson(): ?string {
    return $this->rawJson;
  }

}
