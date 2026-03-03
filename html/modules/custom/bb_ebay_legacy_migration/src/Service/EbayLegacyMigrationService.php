<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use InvalidArgumentException;

final class EbayLegacyMigrationService {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly EbayAccountManager $accountManager,
    private readonly EbayInventoryMirrorSyncService $inventoryMirrorSyncService,
    private readonly EbayOfferMirrorSyncService $offerMirrorSyncService,
  ) {}

  /**
   * Migrate legacy listing IDs in chunks of five and resync mirror after each chunk.
   *
   * @param string[] $listingIds
   *
   * @return array<int,array{listing_ids:string[],response:array}>
   */
  public function migrateListingIds(array $listingIds): array {
    $normalizedListingIds = $this->normalizeListingIds($listingIds);
    if ($normalizedListingIds === []) {
      throw new InvalidArgumentException('At least one eBay Item ID must be provided.');
    }

    $account = $this->accountManager->loadPrimaryAccount();
    $chunks = array_chunk($normalizedListingIds, 5);
    $results = [];

    foreach ($chunks as $chunk) {
      $response = $this->sellApiClient->bulkMigrateListingsForAccount($account, $chunk);
      $this->resyncMirror($account);
      $results[] = [
        'listing_ids' => $chunk,
        'response' => $response,
      ];
    }

    return $results;
  }

  /**
   * @param string[] $listingIds
   *
   * @return string[]
   */
  private function normalizeListingIds(array $listingIds): array {
    $normalizedListingIds = [];

    foreach ($listingIds as $listingId) {
      $trimmedListingId = trim($listingId);
      if ($trimmedListingId === '') {
        continue;
      }

      $normalizedListingIds[] = $trimmedListingId;
    }

    return array_values(array_unique($normalizedListingIds));
  }

  private function resyncMirror(EbayAccount $account): void {
    $this->inventoryMirrorSyncService->syncAll($account);
    $this->offerMirrorSyncService->syncAll($account);
  }

}
