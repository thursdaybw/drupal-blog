<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class ListingSyncExportGraphBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  public function buildForListing(BbAiListing $listing): array {
    $listingId = (int) $listing->id();
    $bundleItems = $this->loadBundleItemsForListing($listingId);
    $inventorySkus = $this->loadInventorySkusForListing($listingId);
    $publications = $this->loadMarketplacePublicationsForListing($listingId);
    $listingImages = $this->loadListingImagesForListing($listingId, $bundleItems);
    $files = $this->loadFilesFromListingImages($listingImages);
    $legacyRows = $this->loadLegacyRowsForListing($listingId);

    return [
      'generated_at' => gmdate(DATE_ATOM, $this->time->getCurrentTime()),
      'root_listing' => $this->buildEntityRecord($listing),
      'relationship_manifest' => $this->buildRelationshipManifest(),
      'entities' => [
        'ai_book_bundle_item' => $this->buildEntityRecords($bundleItems),
        'ai_listing_inventory_sku' => $this->buildEntityRecords($inventorySkus),
        'ai_marketplace_publication' => $this->buildEntityRecords($publications),
        'listing_image' => $this->buildEntityRecords($listingImages),
        'file' => $this->buildEntityRecords($files),
      ],
      'legacy_tables' => $legacyRows,
    ];
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
   * @return array{
   *   bb_ebay_legacy_listing_link: array<int, array<string, mixed>>,
   *   bb_ebay_legacy_listing: array<int, array<string, mixed>>
   * }
   */
  private function loadLegacyRowsForListing(int $listingId): array {
    $links = [];
    $listings = [];

    if (!$this->database->schema()->tableExists('bb_ebay_legacy_listing_link')) {
      return [
        'bb_ebay_legacy_listing_link' => [],
        'bb_ebay_legacy_listing' => [],
      ];
    }

    $linksResult = $this->database->select('bb_ebay_legacy_listing_link', 'l')
      ->fields('l')
      ->condition('listing', $listingId)
      ->execute();

    foreach ($linksResult as $row) {
      $links[] = (array) $row;
    }

    if ($links === [] || !$this->database->schema()->tableExists('bb_ebay_legacy_listing')) {
      return [
        'bb_ebay_legacy_listing_link' => $links,
        'bb_ebay_legacy_listing' => [],
      ];
    }

    foreach ($links as $link) {
      $accountId = (int) ($link['account_id'] ?? 0);
      $ebayListingId = (string) ($link['ebay_listing_id'] ?? '');
      if ($accountId <= 0 || $ebayListingId === '') {
        continue;
      }

      $listingRow = $this->database->select('bb_ebay_legacy_listing', 'x')
        ->fields('x')
        ->condition('account_id', $accountId)
        ->condition('ebay_listing_id', $ebayListingId)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if (is_array($listingRow)) {
        $listings[] = $listingRow;
      }
    }

    return [
      'bb_ebay_legacy_listing_link' => $links,
      'bb_ebay_legacy_listing' => $listings,
    ];
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
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $entities
   *
   * @return array<int, array<string, mixed>>
   */
  private function buildEntityRecords(array $entities): array {
    $records = [];
    foreach ($entities as $entity) {
      $records[] = $this->buildEntityRecord($entity);
    }
    return $records;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEntityRecord(EntityInterface $entity): array {
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => $entity->id(),
      'uuid' => $entity->uuid(),
      'bundle' => $entity->bundle(),
      'values' => $entity->toArray(),
    ];
  }

  /**
   * @return array<string, array<int, array<string, string>>>
   */
  private function buildRelationshipManifest(): array {
    return [
      'reverse_entity_relationships' => [
        [
          'entity_type' => 'ai_book_bundle_item',
          'field' => 'bundle_listing',
          'points_to' => 'bb_ai_listing',
        ],
        [
          'entity_type' => 'ai_listing_inventory_sku',
          'field' => 'listing',
          'points_to' => 'bb_ai_listing',
        ],
        [
          'entity_type' => 'ai_marketplace_publication',
          'field' => 'listing',
          'points_to' => 'bb_ai_listing',
        ],
        [
          'entity_type' => 'listing_image',
          'field' => 'owner',
          'points_to' => 'bb_ai_listing|ai_book_bundle_item',
        ],
      ],
      'non_entity_table_relationships' => [
        [
          'table' => 'bb_ebay_legacy_listing_link',
          'field' => 'listing',
          'points_to' => 'bb_ai_listing.id',
        ],
        [
          'table' => 'bb_ebay_legacy_listing',
          'field' => 'account_id + ebay_listing_id',
          'points_to' => 'bb_ebay_legacy_listing_link.account_id + ebay_listing_id',
        ],
      ],
    ];
  }

}
