<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Contract;

use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * Port for taking down a live marketplace publication.
 */
interface MarketplaceUnpublisherInterface {

  public function supports(string $marketplaceKey): bool;

  public function unpublish(MarketplaceUnpublishRequest $request): int;

}
