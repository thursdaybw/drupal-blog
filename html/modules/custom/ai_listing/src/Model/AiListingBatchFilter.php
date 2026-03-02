<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Model;

final class AiListingBatchFilter {

  public function __construct(
    public readonly string $status,
    public readonly string $bargainBinFilterMode,
    public readonly string $publishedToEbayFilterMode,
    public readonly string $searchQuery,
    public readonly string $storageLocationFilter,
    public readonly int $itemsPerPage,
    public readonly int $currentPage,
  ) {}

}
