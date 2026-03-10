<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\bb_ai_listing_sync\Contract\ListingSyncGraphBuilderInterface;
use Drupal\bb_ai_listing_sync\Model\ListingSyncGraph;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class ListingSyncExportGraphBuilder implements ListingSyncGraphBuilderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function buildForListing(BbAiListing $listing): ListingSyncGraph {
    $listingId = (int) $listing->id();

    $bundleItems = $this->loadBundleItemsForListing($listingId);
    $inventorySkus = $this->loadInventorySkusForListing($listingId);
    $publications = $this->loadMarketplacePublicationsForListing($listingId);
    $listingImages = $this->loadListingImagesForListing($listingId, $bundleItems);
    $files = $this->loadFilesFromListingImages($listingImages);

    $entitiesByType = [
      'bb_ai_listing' => [$listing],
      'ai_book_bundle_item' => $bundleItems,
      'ai_listing_inventory_sku' => $inventorySkus,
      'ai_marketplace_publication' => $publications,
      'listing_image' => $listingImages,
      'file' => $files,
    ];

    $uuids = $this->collectUniqueUuids($entitiesByType);

    $counts = [];
    $total = 0;
    foreach ($entitiesByType as $entityType => $entities) {
      $count = count($entities);
      $counts[$entityType] = $count;
      $total += $count;
    }

    return new ListingSyncGraph(
      entitiesByType: $entitiesByType,
      uuids: $uuids,
      rootListingUuid: (string) $listing->uuid(),
      rootListingId: $listingId,
      counts: $counts,
      totalEntities: $total,
    );
  }

  public function loadAndBuildForListingId(int $listingId): ?ListingSyncGraph {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');
    $listing = $storage->load($listingId);
    if (!$listing instanceof BbAiListing) {
      return NULL;
    }

    return $this->buildForListing($listing);
  }

  /**
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadBundleItemsForListing(int $listingId): array {
    return $this->loadByProperty('ai_book_bundle_item', 'bundle_listing', $listingId);
  }

  /**
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadInventorySkusForListing(int $listingId): array {
    return $this->loadByProperty('ai_listing_inventory_sku', 'listing', $listingId);
  }

  /**
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadMarketplacePublicationsForListing(int $listingId): array {
    return $this->loadByProperty('ai_marketplace_publication', 'listing', $listingId);
  }

  /**
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $bundleItems
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadListingImagesForListing(int $listingId, array $bundleItems): array {
    $imageStorage = $this->entityTypeManager->getStorage('listing_image');
    $ids = [];

    $ids = array_merge($ids, $this->queryListingImageIds('bb_ai_listing', $listingId));

    foreach ($bundleItems as $bundleItem) {
      $bundleItemId = (int) $bundleItem->id();
      $ids = array_merge($ids, $this->queryListingImageIds('ai_book_bundle_item', $bundleItemId));
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
      return [];
    }

    return array_values($imageStorage->loadMultiple($ids));
  }

  /**
   * @return array<int, int|string>
   */
  private function queryListingImageIds(string $ownerType, int $ownerId): array {
    $query = $this->entityTypeManager->getStorage('listing_image')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('owner.target_type', $ownerType);
    $query->condition('owner.target_id', $ownerId);
    return $query->execute();
  }

  /**
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $listingImages
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadFilesFromListingImages(array $listingImages): array {
    $fileIds = [];

    foreach ($listingImages as $listingImage) {
      $fileTargetId = (int) ($listingImage->get('file')->target_id ?? 0);
      if ($fileTargetId > 0) {
        $fileIds[] = $fileTargetId;
      }
    }

    $fileIds = array_values(array_unique($fileIds));
    if ($fileIds === []) {
      return [];
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    return array_values($fileStorage->loadMultiple($fileIds));
  }

  /**
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadByProperty(string $entityType, string $property, int $value): array {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $query = $storage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition($property, $value);
    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }

    return array_values($storage->loadMultiple($ids));
  }

  /**
   * @param array<string, array<int, \Drupal\Core\Entity\EntityInterface>> $entitiesByType
   *
   * @return array<int, string>
   */
  private function collectUniqueUuids(array $entitiesByType): array {
    $byUuid = [];

    foreach ($entitiesByType as $entities) {
      foreach ($entities as $entity) {
        $uuid = (string) $entity->uuid();
        if ($uuid !== '') {
          $byUuid[$uuid] = $uuid;
        }
      }
    }

    $uuids = array_values($byUuid);
    sort($uuids);
    return $uuids;
  }

}
