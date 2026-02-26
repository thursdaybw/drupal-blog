<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\ai_listing\Entity\AiListingInventorySku;
use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class MarketplacePublicationRecorder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function recordSuccessfulPublish(
    AiBookListing $listing,
    AiListingInventorySku $inventorySku,
    string $marketplaceKey,
    MarketplacePublishResult $result,
  ): void {
    $publicationType = trim((string) ($result->getPublicationType() ?? ''));
    $publication = $this->loadPublicationRecord($listing, $marketplaceKey, $publicationType);

    if (!$publication instanceof AiMarketplacePublication) {
      $publication = $this->entityTypeManager
        ->getStorage('ai_marketplace_publication')
        ->create([
          'ai_book_listing' => $listing->id(),
          'marketplace_key' => $marketplaceKey,
          'publication_type' => $publicationType,
        ]);
    }

    $publication->set('ai_book_listing', $listing->id());
    $publication->set('inventory_sku', $inventorySku->id());
    $publication->set('inventory_sku_value', (string) $inventorySku->get('sku')->value);
    $publication->set('marketplace_key', $marketplaceKey);
    $publication->set('status', 'published');
    $publication->set('publication_type', $publicationType);
    $publication->set('marketplace_publication_id', (string) ($result->getMarketplacePublicationId() ?? ''));
    $publication->set('marketplace_listing_id', (string) ($result->getMarketplaceListingId() ?? ''));
    $publication->set('last_error_message', '');
    $publication->set('published_at', time());
    $publication->set('ended_at', null);
    $publication->save();
  }

  private function loadPublicationRecord(
    AiBookListing $listing,
    string $marketplaceKey,
    string $publicationType,
  ): ?AiMarketplacePublication {
    $storage = $this->entityTypeManager->getStorage('ai_marketplace_publication');

    $records = $storage->loadByProperties([
      'ai_book_listing' => $listing->id(),
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
