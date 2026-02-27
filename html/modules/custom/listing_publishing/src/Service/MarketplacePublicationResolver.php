<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class MarketplacePublicationResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function getPublicationForListing(
    BbAiListing $listing,
    string $marketplaceKey,
    string $publicationType = '',
    ?string $status = null,
  ): ?AiMarketplacePublication {
    $properties = [
      'listing' => $listing->id(),
      'marketplace_key' => $marketplaceKey,
      'publication_type' => $publicationType,
    ];
    if ($status !== null) {
      $properties['status'] = $status;
    }

    $records = $this->entityTypeManager
      ->getStorage('ai_marketplace_publication')
      ->loadByProperties($properties);

    if ($records === []) {
      return null;
    }

    uasort($records, static function (AiMarketplacePublication $a, AiMarketplacePublication $b): int {
      return ((int) $a->id()) <=> ((int) $b->id());
    });

    /** @var \Drupal\ai_listing\Entity\AiMarketplacePublication $record */
    $record = end($records);
    return $record instanceof AiMarketplacePublication ? $record : null;
  }

  public function getPublishedPublicationForListing(
    BbAiListing $listing,
    string $marketplaceKey,
    string $publicationType = '',
  ): ?AiMarketplacePublication {
    return $this->getPublicationForListing($listing, $marketplaceKey, $publicationType, 'published');
  }

}
