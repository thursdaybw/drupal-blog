<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Model\ListingImageSource;
use Drupal\listing_publishing\Model\ListingImageUploadResult;

final class EbayMediaImageUploader implements ListingImageUploaderInterface {

  public function __construct(
    private readonly EbayMediaApiClient $mediaApiClient,
  ) {}

  /**
   * @param ListingImageSource[] $sources
   */
  public function upload(array $sources): ListingImageUploadResult {
    $remoteUrls = [];

    foreach ($sources as $source) {
      if (!$source instanceof ListingImageSource) {
        continue;
      }

      $handle = fopen($source->getUri(), 'rb');
      if ($handle === false) {
        throw new \RuntimeException(sprintf('Unable to open image %s for reading.', $source->getUri()));
      }

      try {
        $remoteUrls[] = $this->mediaApiClient->createImageFromStream($handle, $source->getFilename());
      } finally {
        fclose($handle);
      }
    }

    return new ListingImageUploadResult($remoteUrls);
  }

}
