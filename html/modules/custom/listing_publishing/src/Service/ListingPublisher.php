<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class ListingPublisher {

  public function __construct(
    private readonly BookListingAssembler $assembler,
    private readonly MarketplacePublisherInterface $publisher,
  ) {}

  public function publish(AiBookListing $listing): MarketplacePublishResult {
    $request = $this->assembler->assemble($listing);
    return $this->publisher->publish($request);
  }

}
