<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\ebay_infrastructure\Exception\EbayInventoryItemMissingException;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Exception\MarketplaceAlreadyUnpublishedException;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * eBay adapter for taking down a live listing by SKU.
 */
final class EbayMarketplaceUnpublisher implements MarketplaceUnpublisherInterface {

  public function __construct(
    private readonly EbaySkuRemovalService $skuRemovalService,
  ) {}

  public function supports(string $marketplaceKey): bool {
    return trim(strtolower($marketplaceKey)) === 'ebay';
  }

  public function unpublish(MarketplaceUnpublishRequest $request): int {
    try {
      return $this->skuRemovalService->removeSku($request->sku);
    }
    catch (EbayInventoryItemMissingException) {
      throw new MarketplaceAlreadyUnpublishedException(
        $request,
        sprintf('Marketplace resource already missing for SKU %s.', $request->sku),
      );
    }
  }

}
