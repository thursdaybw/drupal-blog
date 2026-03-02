<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

final class MarketplacePublicationLifecycleManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function deleteMarketplacePublicationsBySku(string $marketplaceKey, string $sku): void {
    $normalizedSku = trim($sku);
    if ($normalizedSku === '') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('ai_marketplace_publication');
    $records = $storage->loadByProperties([
      'marketplace_key' => $marketplaceKey,
      'inventory_sku_value' => $normalizedSku,
    ]);

    foreach ($records as $record) {
      $record->delete();
    }
  }

}
