<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Contract;

use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

interface MarketplacePublisherInterface {

  public function publish(ListingPublishRequest $request): MarketplacePublishResult;

  public function deleteSku(string $sku): void;

  public function getMarketplaceKey(): string;

}
