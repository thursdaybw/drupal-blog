<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\ebay_infrastructure\Utility\OfferExceptionHelper;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * eBay adapter for taking down a live listing by SKU.
 */
final class EbayMarketplaceUnpublisher implements MarketplaceUnpublisherInterface {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
  ) {}

  public function supports(string $marketplaceKey): bool {
    return trim(strtolower($marketplaceKey)) === 'ebay';
  }

  public function unpublish(MarketplaceUnpublishRequest $request): int {
    $offers = [];

    try {
      $offers = $this->sellApiClient->listOffersBySku($request->sku);
    }
    catch (\RuntimeException $exception) {
      if (!OfferExceptionHelper::isOfferUnavailable($exception)) {
        throw $exception;
      }
      $offers = ['offers' => []];
    }

    $deletedOfferCount = 0;
    foreach ($offers['offers'] ?? [] as $offer) {
      $offerId = $offer['offerId'] ?? NULL;
      if ($offerId === NULL || $offerId === '') {
        continue;
      }

      try {
        $this->sellApiClient->deleteOffer((string) $offerId);
        $deletedOfferCount++;
      }
      catch (\RuntimeException $exception) {
        if (OfferExceptionHelper::isOfferUnavailable($exception)) {
          continue;
        }
        throw $exception;
      }
    }

    $this->sellApiClient->deleteInventoryItem($request->sku);

    return $deletedOfferCount;
  }

}
