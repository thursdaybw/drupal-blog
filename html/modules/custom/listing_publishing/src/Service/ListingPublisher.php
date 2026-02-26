<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\ai_listing\Service\AiListingInventorySkuResolver;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class ListingPublisher {

  public function __construct(
    private readonly BookListingAssembler $assembler,
    private readonly MarketplacePublisherInterface $publisher,
    private readonly AiListingInventorySkuResolver $skuResolver,
    private readonly MarketplacePublicationRecorder $marketplacePublicationRecorder,
  ) {}

  public function publish(AiBookListing $listing): MarketplacePublishResult {
    $request = $this->assembler->assemble($listing);
    $newSku = $request->getSku();
    $previousSku = $this->skuResolver->getPrimarySku($listing) ?? '';

    if ($previousSku !== '' && $previousSku !== $newSku) {
      $this->publisher->deleteSku($previousSku);
      $this->skuResolver->retirePrimarySku($listing);
    }

    $result = $this->publisher->publish($request);
    if ($result->isSuccess()) {
      $inventorySku = $this->skuResolver->setPrimarySku($listing, $newSku);
      $this->marketplacePublicationRecorder->recordSuccessfulPublish(
        $listing,
        $inventorySku,
        $this->publisher->getMarketplaceKey(),
        $result
      );
    }

    return $result;
  }

}
