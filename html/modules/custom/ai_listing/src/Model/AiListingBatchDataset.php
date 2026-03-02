<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Model;

final class AiListingBatchDataset {

  /**
   * @param array<string,string> $storageLocationOptions
   * @param array<string,array{selection_key:string,listing_type:string,listing_id:int,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int,sku:string,is_published_to_ebay:bool,ebay_listing_id:?string}> $pagedRows
   */
  public function __construct(
    public readonly int $totalCount,
    public readonly int $filteredCount,
    public readonly int $currentPage,
    public readonly array $storageLocationOptions,
    public readonly array $pagedRows,
  ) {}

}
