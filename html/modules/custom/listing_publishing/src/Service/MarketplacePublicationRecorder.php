<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\AiListingInventorySku;
use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class MarketplacePublicationRecorder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function recordSuccessfulPublish(
    BbAiListing $listing,
    AiListingInventorySku $inventorySku,
    string $marketplaceKey,
    MarketplacePublishResult $result,
  ): void {
    $this->recordPublicationSnapshot(
      $listing,
      $inventorySku,
      $marketplaceKey,
      trim((string) ($result->getPublicationType() ?? '')),
      'published',
      $result->getMarketplacePublicationId(),
      $result->getMarketplaceListingId()
    );
  }

  public function recordPublicationSnapshot(
    BbAiListing $listing,
    AiListingInventorySku $inventorySku,
    string $marketplaceKey,
    string $publicationType,
    string $status,
    ?string $marketplacePublicationId = null,
    ?string $marketplaceListingId = null,
    ?string $lastErrorMessage = null,
  ): void {
    $normalizedPublicationType = trim($publicationType);
    $publication = $this->loadPublicationRecord($listing, $marketplaceKey, $normalizedPublicationType);

    if (!$publication instanceof AiMarketplacePublication) {
      $publication = $this->entityTypeManager
        ->getStorage('ai_marketplace_publication')
        ->create([
          'listing' => $listing->id(),
          'marketplace_key' => $marketplaceKey,
          'publication_type' => $normalizedPublicationType,
        ]);
    }

    $publication->set('listing', $listing->id());
    $publication->set('inventory_sku', $inventorySku->id());
    $publication->set('inventory_sku_value', (string) $inventorySku->get('sku')->value);
    $publication->set('marketplace_key', $marketplaceKey);
    $publication->set('status', $status);
    $publication->set('publication_type', $normalizedPublicationType);
    if ($marketplacePublicationId !== null) {
      $publication->set('marketplace_publication_id', $marketplacePublicationId);
    }

    if ($marketplaceListingId !== null) {
      $publication->set('marketplace_listing_id', $marketplaceListingId);
    }

    $publication->set('last_error_message', (string) ($lastErrorMessage ?? ''));

    if ($status === 'published') {
      $publication->set('published_at', time());
      $publication->set('ended_at', null);
    }

    if ($status === 'ended') {
      $publication->set('ended_at', time());
    }

    $publication->save();
  }

  private function loadPublicationRecord(
    BbAiListing $listing,
    string $marketplaceKey,
    string $publicationType,
  ): ?AiMarketplacePublication {
    $storage = $this->entityTypeManager->getStorage('ai_marketplace_publication');

    $records = $storage->loadByProperties([
      'listing' => $listing->id(),
      'marketplace_key' => $marketplaceKey,
      'publication_type' => $publicationType,
    ]);

    if ($records === []) {
      return null;
    }

    /** @var \Drupal\ai_listing\Entity\AiMarketplacePublication $record */
    $record = reset($records);
    return $record;
  }

}
