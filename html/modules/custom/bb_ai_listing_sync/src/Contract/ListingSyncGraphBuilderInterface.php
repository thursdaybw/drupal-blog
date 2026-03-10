<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Contract;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\bb_ai_listing_sync\Model\ListingSyncGraph;

interface ListingSyncGraphBuilderInterface {

  public function buildForListing(BbAiListing $listing): ListingSyncGraph;

  public function loadAndBuildForListingId(int $listingId): ?ListingSyncGraph;

}

