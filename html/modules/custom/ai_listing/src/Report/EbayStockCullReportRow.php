<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Report;

/**
 * Immutable read model row for the eBay stock cull report.
 */
final class EbayStockCullReportRow {

  public function __construct(
    public readonly int $listingId,
    public readonly string $listingType,
    public readonly string $title,
    public readonly string $price,
    public readonly string $storageLocation,
    public readonly string $inventorySku,
    public readonly string $marketplaceListingId,
    public readonly string $source,
    public readonly ?int $marketplaceStartedAt,
    public readonly ?int $publishedAt,
  ) {}

  public function effectiveListedAt(): ?int {
    return $this->marketplaceStartedAt ?? $this->publishedAt;
  }

  public function priceAsFloat(): ?float {
    if (!is_numeric($this->price)) {
      return NULL;
    }

    $price = (float) $this->price;
    return $price > 0 ? $price : NULL;
  }

  public function ageDays(int $requestTime): ?int {
    $effectiveListedAt = $this->effectiveListedAt();
    if ($effectiveListedAt === NULL) {
      return NULL;
    }

    return (int) floor(($requestTime - $effectiveListedAt) / 86400);
  }

  public function ageMonths(int $requestTime): ?float {
    $ageDays = $this->ageDays($requestTime);
    if ($ageDays === NULL) {
      return NULL;
    }

    return $ageDays / 30.4375;
  }

  public function cullScore(int $requestTime): ?float {
    $ageMonths = $this->ageMonths($requestTime);
    $price = $this->priceAsFloat();
    if ($ageMonths === NULL || $price === NULL) {
      return NULL;
    }

    return $ageMonths / $price;
  }

}
