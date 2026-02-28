<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\AiListingInventorySku;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class AiListingInventorySkuResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function getSku(BbAiListing $listing): ?string {
    $skuRecord = $this->loadCurrentSkuRecord($listing);
    if (!$skuRecord instanceof AiListingInventorySku) {
      return null;
    }

    return (string) $skuRecord->get('sku')->value;
  }

  public function getSkuRecord(BbAiListing $listing): ?AiListingInventorySku {
    return $this->loadCurrentSkuRecord($listing);
  }

  public function setSku(BbAiListing $listing, string $sku): AiListingInventorySku {
    $normalizedSku = trim($sku);
    if ($normalizedSku === '') {
      throw new \InvalidArgumentException('SKU cannot be empty.');
    }

    if (!$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      throw new \RuntimeException('Inventory SKU entity type is not installed.');
    }

    $skuRecord = $this->loadCurrentSkuRecord($listing);

    if (!$skuRecord instanceof AiListingInventorySku) {
      $skuRecord = $this->entityTypeManager
        ->getStorage('ai_listing_inventory_sku')
        ->create([
        'listing' => $listing->id(),
      ]);
    }

    $skuRecord->set('sku', $normalizedSku);
    $skuRecord->set('status', 'active');
    $skuRecord->save();

    return $skuRecord;
  }

  public function deleteSku(BbAiListing $listing): void {
    if (!$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      return;
    }

    $skuRecord = $this->loadCurrentSkuRecord($listing);
    if ($skuRecord instanceof AiListingInventorySku) {
      $skuRecord->delete();
    }
  }

  private function loadCurrentSkuRecord(BbAiListing $listing): ?AiListingInventorySku {
    if (!$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      return null;
    }

    $storage = $this->entityTypeManager->getStorage('ai_listing_inventory_sku');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', (int) $listing->id())
      ->condition('status', 'active')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return null;
    }
    $id = (int) reset($ids);
    $record = $storage->load($id);
    return $record instanceof AiListingInventorySku ? $record : null;
  }

}
