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
      $this->skuResolver->setPrimarySku($listing, $newSku);
    }

    return $result;
  }

}
