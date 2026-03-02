<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Model\ListingImageUploadResult;

final class NullListingImageUploader implements ListingImageUploaderInterface {

  public function upload(array $sources): ListingImageUploadResult {
    return new ListingImageUploadResult([]);
  }

}
