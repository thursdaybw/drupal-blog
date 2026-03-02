<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class NullMarketplacePublisher implements MarketplacePublisherInterface {

  public function publish(ListingPublishRequest $request): MarketplacePublishResult {
    throw new \RuntimeException('No marketplace publisher is configured.');
  }

  public function updatePublication(
    string $marketplacePublicationId,
    ListingPublishRequest $request,
    ?string $publicationType = null,
  ): MarketplacePublishResult {
    throw new \RuntimeException('No marketplace publisher is configured.');
  }

  public function deleteSku(string $sku): void {
    throw new \RuntimeException('No marketplace publisher is configured.');
  }

  public function getMarketplaceKey(): string {
    return 'none';
  }

}
