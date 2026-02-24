<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Contract;

use Drupal\listing_publishing\Model\ListingImageSource;
use Drupal\listing_publishing\Model\ListingImageUploadResult;

interface ListingImageUploaderInterface {

  /**
   * Upload the provided listing image sources and return the marketplace URLs.
   *
   * @param \Drupal\listing_publishing\Model\ListingImageSource[] $sources
   *   Local image references owned by the listing.
   *
   * @return \Drupal\listing_publishing\Model\ListingImageUploadResult
   */
  public function upload(array $sources): ListingImageUploadResult;

}
