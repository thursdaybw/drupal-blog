<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

final class ListingImageUploadResult {

  public function __construct(private readonly array $remoteUrls) {}

  public function getRemoteUrls(): array {
    return $this->remoteUrls;
  }

}
