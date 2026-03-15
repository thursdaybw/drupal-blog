<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class ListingGraphPruneService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListingGraphPayloadRuntimeStore $runtimeStore,
  ) {}

  /**
   * @param array<string, mixed> $payload
   */
  public function stageImportPayload(string $listingUuid, array $payload): void {
    $expected = $payload['expected_uuids'] ?? NULL;
    if (!is_array($expected)) {
      return;
    }

    $normalized = [];
    foreach ($expected as $entityType => $uuids) {
      if (!is_string($entityType) || !is_array($uuids)) {
        continue;
      }

      $clean = [];
      foreach ($uuids as $uuid) {
        if (is_string($uuid) && $uuid !== '') {
          $clean[$uuid] = $uuid;
        }
      }
      $normalized[$entityType] = array_values($clean);
    }

    $this->runtimeStore->setPayload($listingUuid, [
      'expected_uuids' => $normalized,
    ]);
  }

  /**
   * @return array{
   *   bundle_item_deletes:int,
   *   inventory_sku_deletes:int,
   *   publication_deletes:int,
   *   listing_image_deletes:int
   * }
   */
  public function applyPayloadForListing(string $listingUuid, int $listingId): array {
    $summary = [
      'bundle_item_deletes' => 0,
      'inventory_sku_deletes' => 0,
      'publication_deletes' => 0,
      'listing_image_deletes' => 0,
    ];

    $payload = $this->runtimeStore->getPayload($listingUuid);
    if (!is_array($payload)) {
      return $summary;
    }

    $expected = $payload['expected_uuids'] ?? [];
    if (!is_array($expected)) {
      $this->runtimeStore->clearPayload($listingUuid);
      return $summary;
    }

    $bundleStorage = $this->entityTypeManager->getStorage('ai_book_bundle_item');
    $bundleIdsBeforePrune = $this->loadIdsByProperty($bundleStorage, 'bundle_listing', $listingId);

    $summary['bundle_item_deletes'] = $this->pruneByListingProperty(
      'ai_book_bundle_item',
      'bundle_listing',
      $listingId,
      $this->uuidSet($expected['ai_book_bundle_item'] ?? [])
    );
    $summary['inventory_sku_deletes'] = $this->pruneByListingProperty(
      'ai_listing_inventory_sku',
      'listing',
      $listingId,
      $this->uuidSet($expected['ai_listing_inventory_sku'] ?? [])
    );
    $summary['publication_deletes'] = $this->pruneByListingProperty(
      'ai_marketplace_publication',
      'listing',
      $listingId,
      $this->uuidSet($expected['ai_marketplace_publication'] ?? [])
    );

    $summary['listing_image_deletes'] = $this->pruneListingImages(
      $listingId,
      $bundleIdsBeforePrune,
      $this->uuidSet($expected['listing_image'] ?? [])
    );

    $this->runtimeStore->clearPayload($listingUuid);
    return $summary;
  }

  /**
   * @param array<int, mixed> $uuids
   *
   * @return array<string, bool>
   */
  private function uuidSet(array $uuids): array {
    $set = [];
    foreach ($uuids as $uuid) {
      if (is_string($uuid) && $uuid !== '') {
        $set[$uuid] = TRUE;
      }
    }
    return $set;
  }

  /**
   * @param array<string, bool> $expectedUuids
   */
  private function pruneByListingProperty(string $entityType, string $property, int $listingId, array $expectedUuids): int {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $ids = $this->loadIdsByProperty($storage, $property, $listingId);
    if ($ids === []) {
      return 0;
    }

    $entities = $storage->loadMultiple($ids);
    $toDelete = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      $uuid = (string) $entity->uuid();
      if ($uuid === '' || !isset($expectedUuids[$uuid])) {
        $toDelete[] = $entity;
      }
    }

    if ($toDelete === []) {
      return 0;
    }

    $storage->delete($toDelete);
    return count($toDelete);
  }

  /**
   * @param array<int, int> $bundleIdsBeforePrune
   * @param array<string, bool> $expectedImageUuids
   */
  private function pruneListingImages(int $listingId, array $bundleIdsBeforePrune, array $expectedImageUuids): int {
    $imageStorage = $this->entityTypeManager->getStorage('listing_image');
    $query = $imageStorage->getQuery();
    $query->accessCheck(FALSE);

    $owners = $query->orConditionGroup();
    $owners->condition(
      $query->andConditionGroup()
        ->condition('owner.target_type', 'bb_ai_listing')
        ->condition('owner.target_id', $listingId)
    );

    if ($bundleIdsBeforePrune !== []) {
      $owners->condition(
        $query->andConditionGroup()
          ->condition('owner.target_type', 'ai_book_bundle_item')
          ->condition('owner.target_id', $bundleIdsBeforePrune, 'IN')
      );
    }

    $query->condition($owners);
    $ids = array_values(array_map('intval', $query->execute()));
    if ($ids === []) {
      return 0;
    }

    $entities = $imageStorage->loadMultiple($ids);
    $toDelete = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      $uuid = (string) $entity->uuid();
      if ($uuid === '' || !isset($expectedImageUuids[$uuid])) {
        $toDelete[] = $entity;
      }
    }

    if ($toDelete === []) {
      return 0;
    }

    $imageStorage->delete($toDelete);
    return count($toDelete);
  }

  /**
   * @return array<int, int>
   */
  private function loadIdsByProperty(EntityStorageInterface $storage, string $property, int $value): array {
    $query = $storage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition($property, $value);
    return array_values(array_map('intval', $query->execute()));
  }

}

