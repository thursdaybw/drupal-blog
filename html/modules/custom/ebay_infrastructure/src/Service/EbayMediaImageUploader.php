<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Model\ListingImageSource;
use Drupal\listing_publishing\Model\ListingImageUploadResult;

final class EbayMediaImageUploader implements ListingImageUploaderInterface {

  public function __construct(
    private readonly EbayMediaApiClient $mediaApiClient,
    private readonly FileSystemInterface $fileSystem,
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

      $path = $this->fileSystem->realpath($source->getUri());
      if ($path === FALSE || !is_file($path)) {
        throw new \RuntimeException(sprintf('Unable to resolve image %s.', $source->getUri()));
      }

      $remoteUrls[] = $this->mediaApiClient->createImageFromFile($path, $source->getFilename());
    }

    return new ListingImageUploadResult($remoteUrls);
  }

}
