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

  public function getPrimarySku(BbAiListing $listing): ?string {
    $skuRecord = $this->loadPrimarySkuRecord($listing);
    if (!$skuRecord instanceof AiListingInventorySku) {
      return null;
    }

    return (string) $skuRecord->get('sku')->value;
  }

  public function getPrimarySkuRecord(BbAiListing $listing): ?AiListingInventorySku {
    return $this->loadPrimarySkuRecord($listing);
  }

  public function setPrimarySku(BbAiListing $listing, string $sku): AiListingInventorySku {
    $normalizedSku = trim($sku);
    if ($normalizedSku === '') {
      throw new \InvalidArgumentException('Primary SKU cannot be empty.');
    }

    if (!$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      throw new \RuntimeException('Inventory SKU entity type is not installed.');
    }

    $skuRecord = $this->loadPrimarySkuRecord($listing);

    if (!$skuRecord instanceof AiListingInventorySku) {
      $skuRecord = $this->entityTypeManager
        ->getStorage('ai_listing_inventory_sku')
        ->create([
        'listing' => $listing->id(),
        'is_primary' => TRUE,
      ]);
    }

    $skuRecord->set('sku', $normalizedSku);
    $skuRecord->set('status', 'active');
    $skuRecord->save();

    return $skuRecord;
  }

  public function retirePrimarySku(BbAiListing $listing): void {
    if (!$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      return;
    }

    $skuRecord = $this->loadPrimarySkuRecord($listing);
    if ($skuRecord instanceof AiListingInventorySku) {
      $skuRecord->set('status', 'retired');
      $skuRecord->save();
    }
  }

  private function loadPrimarySkuRecord(BbAiListing $listing): ?AiListingInventorySku {
    if (!$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      return null;
    }

    $storage = $this->entityTypeManager->getStorage('ai_listing_inventory_sku');
    $records = $storage->loadByProperties([
      'listing' => $listing->id(),
      'is_primary' => 1,
      'status' => 'active',
    ]);

    if ($records === []) {
      return null;
    }

    /** @var \Drupal\ai_listing\Entity\AiListingInventorySku $record */
    $record = reset($records);
    return $record;
  }

}
