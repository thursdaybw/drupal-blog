<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\ai_listing\Service\AiListingInventorySkuResolver;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class ListingPublisher {

  public function __construct(
    private readonly BookListingAssembler $assembler,
    private readonly MarketplacePublisherInterface $publisher,
    private readonly AiListingInventorySkuResolver $skuResolver,
    private readonly MarketplacePublicationRecorder $marketplacePublicationRecorder,
    private readonly MarketplacePublicationResolver $marketplacePublicationResolver,
    private readonly MarketplacePublicationLifecycleManager $marketplacePublicationLifecycleManager,
  ) {}

  public function publish(BbAiListing $listing): MarketplacePublishResult {
    $request = $this->assembler->assemble($listing);
    $newSku = $request->getSku();
    $previousSku = $this->skuResolver->getSku($listing) ?? '';

    if ($previousSku !== '' && $previousSku !== $newSku) {
      $this->publisher->deleteSku($previousSku);
      $this->marketplacePublicationLifecycleManager->markMarketplacePublicationsEndedBySku(
        $this->publisher->getMarketplaceKey(),
        $previousSku
      );
      $this->skuResolver->deleteSku($listing);
    }

    $result = $this->publisher->publish($request);
    if ($result->isSuccess()) {
      $inventorySku = $this->skuResolver->setSku($listing, $newSku);
      $this->marketplacePublicationRecorder->recordSuccessfulPublish(
        $listing,
        $inventorySku,
        $this->publisher->getMarketplaceKey(),
        $result
      );
    }

    return $result;
  }

  public function publishOrUpdate(BbAiListing $listing): MarketplacePublishResult {
    $publication = $this->marketplacePublicationResolver->getPublishedPublicationForListing(
      $listing,
      $this->publisher->getMarketplaceKey(),
      'FIXED_PRICE'
    );
    if ($publication === null) {
      return $this->publish($listing);
    }

    return $this->updatePublishedListing($listing, $publication);
  }

  private function updatePublishedListing(BbAiListing $listing, AiMarketplacePublication $publication): MarketplacePublishResult {
    $inventorySku = $this->skuResolver->getSkuRecord($listing);
    if ($inventorySku === null) {
      throw new \RuntimeException('Listing has no active inventory SKU record for marketplace update.');
    }

    $publicationId = trim((string) ($publication->get('marketplace_publication_id')->value ?? ''));
    if ($publicationId === '') {
      throw new \RuntimeException('Marketplace publication record is missing publication ID.');
    }

    $request = $this->assembler->assemble($listing);
    $request = $request->withSku((string) $inventorySku->get('sku')->value);

    $publicationType = (string) ($publication->get('publication_type')->value ?? '');
    $result = $this->publisher->updatePublication($publicationId, $request, $publicationType);

    if ($result->isSuccess()) {
      $this->marketplacePublicationRecorder->recordPublicationSnapshot(
        $listing,
        $inventorySku,
        $this->publisher->getMarketplaceKey(),
        $publicationType,
        'published',
        $result->getMarketplacePublicationId(),
        $result->getMarketplaceListingId()
      );
    }

    return $result;
  }

}
