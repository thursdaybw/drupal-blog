<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Model\ListingCullResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * Application use-case for culling a listing from operational inventory.
 */
final class ListingCullService {

  /**
   * @param iterable<int,\Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface> $marketplaceUnpublishers
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly iterable $marketplaceUnpublishers,
    private readonly ListingHistoryRecorder $historyRecorder,
  ) {}

  public function cull(BbAiListing $listing, ?string $reasonCode = NULL, string $note = ''): ListingCullResult {
    $listingId = (int) $listing->id();
    if ($listingId <= 0) {
      throw new \InvalidArgumentException('Cull action requires a saved listing.');
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
      $adapter->unpublish($request);
      $publication->delete();
      $unpublishedCount++;
      if ($marketplaceKey !== '') {
        $marketplaces[] = $marketplaceKey;
      }

      $message = sprintf(
        'Unpublished %s marketplace record for SKU %s%s.',
        $marketplaceKey !== '' ? $marketplaceKey : 'unknown',
        $sku !== '' ? $sku : '(missing SKU)',
        $marketplaceListingId !== '' ? ' (listing ' . $marketplaceListingId . ')' : '',
      );
      $this->historyRecorder->record($listingId, 'marketplace_unpublished', $message, $reasonCode, [
        'marketplace_key' => $marketplaceKey,
        'sku' => $sku,
        'marketplace_listing_id' => $marketplaceListingId,
      ]);
    }

    $listing->set('status', 'archived');
    $listing->save();
    $this->historyRecorder->record($listingId, 'listing_archived', 'Listing archived.', $reasonCode);

    $summary = sprintf(
      'Cull action completed: unpublished %d marketplace record(s) and archived listing.',
      $unpublishedCount,
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
