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
    private readonly MarketplacePublicationResolver $marketplacePublicationResolver,
    private readonly MarketplacePublicationLifecycleManager $marketplacePublicationLifecycleManager,
  ) {}

  public function publish(AiBookListing $listing): MarketplacePublishResult {
    $request = $this->assembler->assemble($listing);
    $newSku = $request->getSku();
    $previousSku = $this->skuResolver->getPrimarySku($listing) ?? '';

    if ($previousSku !== '' && $previousSku !== $newSku) {
      $this->publisher->deleteSku($previousSku);
      $this->marketplacePublicationLifecycleManager->markMarketplacePublicationsEndedBySku(
        $this->publisher->getMarketplaceKey(),
        $previousSku
      );
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

  public function publishOrUpdate(AiBookListing $listing): MarketplacePublishResult {
    $status = (string) ($listing->get('status')->value ?? '');
    if ($status !== 'published') {
      return $this->publish($listing);
    }

    return $this->updatePublishedListing($listing);
  }

  private function updatePublishedListing(AiBookListing $listing): MarketplacePublishResult {
    $inventorySku = $this->skuResolver->getPrimarySkuRecord($listing);
    if ($inventorySku === null) {
      throw new \RuntimeException('Published listing has no primary inventory SKU record.');
    }

    $publication = $this->marketplacePublicationResolver->getPublicationForListing(
      $listing,
      $this->publisher->getMarketplaceKey(),
      'FIXED_PRICE'
    );
    if ($publication === null) {
      throw new \RuntimeException('Published listing has no stored marketplace publication record.');
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
