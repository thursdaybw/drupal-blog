<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Model\ListingCullResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Exception\MarketplaceAlreadyUnpublishedException;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * Application use-case for culling a listing from operational inventory.
 */
final class ListingCullService {

  public const TARGET_ARCHIVED = 'archived';
  public const TARGET_LOST = 'lost';

  /**
   * @param iterable<int,\Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface> $marketplaceUnpublishers
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly iterable $marketplaceUnpublishers,
    private readonly ListingHistoryRecorder $historyRecorder,
  ) {}

  public function cull(
    BbAiListing $listing,
    ?string $reasonCode = NULL,
    string $note = '',
    string $targetStatus = self::TARGET_ARCHIVED,
  ): ListingCullResult {
    $listingId = (int) $listing->id();
    if ($listingId <= 0) {
      throw new \InvalidArgumentException('Cull action requires a saved listing.');
    }
    if (!in_array($targetStatus, [self::TARGET_ARCHIVED, self::TARGET_LOST], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unsupported cull target status "%s".', $targetStatus));
    }

    $publicationStorage = $this->entityTypeManager->getStorage('ai_marketplace_publication');
    $publicationIds = $publicationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingId)
      ->sort('marketplace_key', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    $unpublishedCount = 0;
    $marketplaces = [];
    foreach ($publicationStorage->loadMultiple($publicationIds) as $publication) {
      $publicationId = (int) $publication->id();
      $marketplaceKey = trim((string) ($publication->get('marketplace_key')->value ?? ''));
      $sku = trim((string) ($publication->get('inventory_sku_value')->value ?? ''));
      $marketplaceListingId = trim((string) ($publication->get('marketplace_listing_id')->value ?? ''));

      $request = new MarketplaceUnpublishRequest(
        publicationId: $publicationId,
        marketplaceKey: $marketplaceKey,
        sku: $sku,
        marketplacePublicationId: trim((string) ($publication->get('marketplace_publication_id')->value ?? '')),
        marketplaceListingId: $marketplaceListingId,
      );

      if ($request->marketplaceKey === '') {
        throw new \InvalidArgumentException('Marketplace publication is missing marketplace key.');
      }
      if ($request->sku === '') {
        throw new \InvalidArgumentException('Marketplace publication is missing SKU.');
      }

      $adapter = $this->resolveAdapter($request->marketplaceKey);
      $alreadyUnpublished = false;
      try {
        $adapter->unpublish($request);
      }
      catch (MarketplaceAlreadyUnpublishedException) {
        $alreadyUnpublished = true;
      }

      $publication->delete();
      if ($marketplaceKey !== '') {
        $marketplaces[] = $marketplaceKey;
      }

      if ($alreadyUnpublished) {
        $message = sprintf(
          '%s marketplace record was already absent for SKU %s%s. Removed local publication record.',
          $marketplaceKey !== '' ? ucfirst($marketplaceKey) : 'Unknown',
          $sku !== '' ? $sku : '(missing SKU)',
          $marketplaceListingId !== '' ? ' (listing ' . $marketplaceListingId . ')' : '',
        );
        $eventType = 'marketplace_already_unpublished';
      }
      else {
        $unpublishedCount++;
        $message = sprintf(
          'Unpublished %s marketplace record for SKU %s%s.',
          $marketplaceKey !== '' ? $marketplaceKey : 'unknown',
          $sku !== '' ? $sku : '(missing SKU)',
          $marketplaceListingId !== '' ? ' (listing ' . $marketplaceListingId . ')' : '',
        );
        $eventType = 'marketplace_unpublished';
      }

      $this->historyRecorder->record($listingId, $eventType, $message, $reasonCode, [
        'marketplace_key' => $marketplaceKey,
        'sku' => $sku,
        'marketplace_listing_id' => $marketplaceListingId,
        'already_unpublished' => $alreadyUnpublished,
      ]);
    }

    $listing->set('status', $targetStatus);
    $listing->save();
    $this->historyRecorder->record(
      $listingId,
      $targetStatus === self::TARGET_LOST ? 'listing_lost' : 'listing_archived',
      $targetStatus === self::TARGET_LOST ? 'Listing marked lost.' : 'Listing archived.',
      $reasonCode
    );

    $summary = sprintf(
      'Cull action completed: unpublished %d marketplace record(s) and marked listing %s.',
      $unpublishedCount,
      $targetStatus,
    );
    if (trim($note) !== '') {
      $summary .= ' Note: ' . trim($note);
    }

    $this->historyRecorder->record($listingId, 'culled', $summary, $reasonCode, [
      'unpublished_count' => $unpublishedCount,
      'marketplaces' => array_values(array_unique(array_filter($marketplaces))),
      'note' => trim($note),
    ]);

    return new ListingCullResult(
      unpublishedCount: $unpublishedCount,
      marketplaces: array_values(array_unique(array_filter($marketplaces))),
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
