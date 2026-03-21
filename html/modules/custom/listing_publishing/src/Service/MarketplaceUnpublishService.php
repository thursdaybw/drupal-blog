<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_listing\Service\ListingHistoryRecorder;
use Drupal\ai_listing\Service\MarketplaceLifecycleRecorder;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Exception\MarketplaceAlreadyUnpublishedException;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;
use Drupal\listing_publishing\Model\MarketplaceUnpublishResult;

/**
 * Application use-case for removing a listing from a marketplace.
 */
final class MarketplaceUnpublishService {

  /**
   * @param iterable<int,\Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface> $marketplaceUnpublishers
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly iterable $marketplaceUnpublishers,
    private readonly MarketplaceLifecycleRecorder $marketplaceLifecycleRecorder,
    private readonly ListingHistoryRecorder $listingHistoryRecorder,
  ) {}

  public function unpublishPublication(int $publicationId): MarketplaceUnpublishResult {
    $publication = $this->entityTypeManager
      ->getStorage('ai_marketplace_publication')
      ->load($publicationId);

    if ($publication === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown marketplace publication ID %d.',
        $publicationId
      ));
    }

    $request = new MarketplaceUnpublishRequest(
      publicationId: $publicationId,
      marketplaceKey: trim((string) ($publication->get('marketplace_key')->value ?? '')),
      sku: trim((string) ($publication->get('inventory_sku_value')->value ?? '')),
      marketplacePublicationId: trim((string) ($publication->get('marketplace_publication_id')->value ?? '')),
      marketplaceListingId: trim((string) ($publication->get('marketplace_listing_id')->value ?? '')),
    );

    if ($request->marketplaceKey === '') {
      throw new \InvalidArgumentException('Marketplace publication is missing marketplace key.');
    }
    if ($request->sku === '') {
      throw new \InvalidArgumentException('Marketplace publication is missing SKU.');
    }

    $adapter = $this->resolveAdapter($request->marketplaceKey);
    $deletedOfferCount = 0;
    $alreadyUnpublished = false;
    try {
      $deletedOfferCount = $adapter->unpublish($request);
    }
    catch (MarketplaceAlreadyUnpublishedException) {
      $alreadyUnpublished = true;
    }

    $listingId = (int) ($publication->get('listing')->target_id ?? 0);
    $this->marketplaceLifecycleRecorder->recordUnpublished(
      $listingId,
      $request->marketplaceKey,
    );

    $eventType = $alreadyUnpublished
      ? 'marketplace_already_unpublished'
      : 'marketplace_unpublished';
    $message = $alreadyUnpublished
      ? sprintf(
        '%s SKU %s was already unpublished on the marketplace. Removed the local publication record.',
        $request->marketplaceKey,
        $request->sku
      )
      : sprintf(
        'Unpublished %s SKU %s and removed the local publication record.',
        $request->marketplaceKey,
        $request->sku
      );
    $this->listingHistoryRecorder->record($listingId, $eventType, $message, NULL, [
      'marketplace_key' => $request->marketplaceKey,
      'marketplace_publication_id' => $request->marketplacePublicationId,
      'marketplace_listing_id' => $request->marketplaceListingId,
      'sku' => $request->sku,
      'deleted_offer_count' => $deletedOfferCount,
      'already_unpublished' => $alreadyUnpublished,
    ]);

    $publication->delete();

    return new MarketplaceUnpublishResult(
      publicationId: $request->publicationId,
      marketplaceKey: $request->marketplaceKey,
      sku: $request->sku,
      deletedOfferCount: $deletedOfferCount,
      alreadyUnpublished: $alreadyUnpublished,
    );
  }

  private function resolveAdapter(string $marketplaceKey): MarketplaceUnpublisherInterface {
    foreach ($this->marketplaceUnpublishers as $marketplaceUnpublisher) {
      if ($marketplaceUnpublisher->supports($marketplaceKey)) {
        return $marketplaceUnpublisher;
      }
    }

    throw new \InvalidArgumentException(sprintf(
      'No marketplace unpublisher is registered for "%s".',
      $marketplaceKey
    ));
  }

}
