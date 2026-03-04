<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\Core\Database\Connection;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use InvalidArgumentException;
use RuntimeException;

final class EbayLegacyMigrationService {

  public function __construct(
    private readonly Connection $database,
    private readonly EbayTradingLegacyClient $tradingLegacyClient,
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
   * Prepare one legacy listing for migration, then migrate it immediately.
   *
   * @return array{
   *   listing_id:string,
   *   status:string,
   *   previous_sku:?string,
   *   prepared_sku:?string,
   *   sku_change_reason:?string,
   *   migrate_response:?array
   * }
   */
  public function prepareAndMigrateListingId(string $listingId): array {
    $normalizedListingId = trim($listingId);
    if ($normalizedListingId === '') {
      throw new InvalidArgumentException('A legacy eBay Item ID is required.');
    }

    $account = $this->accountManager->loadPrimaryAccount();
    $legacyListing = $this->loadLegacyListing($account, $normalizedListingId);
    if ($legacyListing === NULL) {
      throw new RuntimeException('Legacy listing ' . $normalizedListingId . ' was not found in the local legacy mirror.');
    }

    if ($this->hasMirroredOffer($account, $normalizedListingId)) {
      return [
        'listing_id' => $normalizedListingId,
        'status' => 'already_migrated',
        'previous_sku' => $legacyListing['sku'],
        'prepared_sku' => $legacyListing['sku'],
        'sku_change_reason' => NULL,
        'migrate_response' => NULL,
      ];
    }

    $previousSku = $legacyListing['sku'];
    $preparedSku = $previousSku;
    $skuChangeReason = NULL;

    if ($this->isMissingSku($previousSku)) {
      $preparedSku = $this->generateUniqueLegacySku($account, $normalizedListingId);
      $skuChangeReason = 'missing_legacy_sku';
    }
    elseif ($this->hasDuplicateLegacySku($account, $normalizedListingId, $previousSku)) {
      $preparedSku = $this->generateUniqueNormalizedSku($account, $previousSku, $normalizedListingId);
      $skuChangeReason = 'duplicate_legacy_sku';
    }

    if ($preparedSku !== $previousSku) {
      $this->tradingLegacyClient->reviseFixedPriceItemSkuForAccount($account, $normalizedListingId, $preparedSku);
      $this->updateLegacyMirrorSku($account, $normalizedListingId, $preparedSku);
    }

    $response = $this->sellApiClient->bulkMigrateListingsForAccount($account, [$normalizedListingId]);
    $this->resyncMirror($account);

    return [
      'listing_id' => $normalizedListingId,
      'status' => $this->didMigrationSucceed($response, $normalizedListingId) ? 'migrated' : 'failed_migration',
      'previous_sku' => $previousSku,
      'prepared_sku' => $preparedSku,
      'sku_change_reason' => $skuChangeReason,
      'migrate_response' => $response,
    ];
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

  /**
   * @return array{sku:?string}|null
   */
  private function loadLegacyListing(EbayAccount $account, string $listingId): ?array {
    $row = $this->database->select('bb_ebay_legacy_listing', 'l')
      ->fields('l', ['sku'])
      ->condition('account_id', (int) $account->id())
      ->condition('ebay_listing_id', $listingId)
      ->execute()
      ->fetchAssoc();

    if (!is_array($row)) {
      return NULL;
    }

    return [
      'sku' => $this->normalizeNullableString((string) ($row['sku'] ?? '')),
    ];
  }

  private function hasMirroredOffer(EbayAccount $account, string $listingId): bool {
    $count = $this->database->select('bb_ebay_offer', 'o')
      ->condition('account_id', (int) $account->id())
      ->condition('listing_id', $listingId)
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count > 0;
  }

  private function isMissingSku(?string $sku): bool {
    return $this->normalizeNullableString((string) $sku) === NULL;
  }

  private function hasDuplicateLegacySku(EbayAccount $account, string $listingId, ?string $sku): bool {
    $normalizedSku = $this->normalizeNullableString((string) $sku);
    if ($normalizedSku === NULL) {
      return FALSE;
    }

    $count = $this->database->select('bb_ebay_legacy_listing', 'l')
      ->condition('account_id', (int) $account->id())
      ->condition('sku', $normalizedSku)
      ->condition('ebay_listing_id', $listingId, '<>')
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count > 0;
  }

  private function generateUniqueLegacySku(EbayAccount $account, string $listingId): string {
    $baseSku = 'legacy-ebay-' . $listingId;
    return $this->generateUniqueSkuFromBase($account, $baseSku);
  }

  private function generateUniqueNormalizedSku(EbayAccount $account, string $currentSku, string $listingId): string {
    $baseSku = $currentSku . '-M';
    $counter = 2;

    while (TRUE) {
      $candidateSku = $baseSku . $counter;
      if (!$this->skuExistsAnywhere($account, $candidateSku, $listingId)) {
        return $candidateSku;
      }

      $counter++;
    }
  }

  private function generateUniqueSkuFromBase(EbayAccount $account, string $baseSku): string {
    if (!$this->skuExistsAnywhere($account, $baseSku)) {
      return $baseSku;
    }

    $counter = 2;
    while (TRUE) {
      $candidateSku = $baseSku . '-' . $counter;
      if (!$this->skuExistsAnywhere($account, $candidateSku)) {
        return $candidateSku;
      }

      $counter++;
    }
  }

  private function skuExistsAnywhere(EbayAccount $account, string $sku, ?string $ignoreLegacyListingId = NULL): bool {
    $legacyQuery = $this->database->select('bb_ebay_legacy_listing', 'l')
      ->condition('account_id', (int) $account->id())
      ->condition('sku', $sku);
    if ($ignoreLegacyListingId !== NULL) {
      $legacyQuery->condition('ebay_listing_id', $ignoreLegacyListingId, '<>');
    }

    $legacyCount = $legacyQuery
      ->countQuery()
      ->execute()
      ->fetchField();

    if ((int) $legacyCount > 0) {
      return TRUE;
    }

    $sellCount = $this->database->select('bb_ebay_inventory_item', 'i')
      ->condition('account_id', (int) $account->id())
      ->condition('sku', $sku)
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $sellCount > 0;
  }

  private function updateLegacyMirrorSku(EbayAccount $account, string $listingId, string $sku): void {
    $this->database->update('bb_ebay_legacy_listing')
      ->fields([
        'sku' => $sku,
      ])
      ->condition('account_id', (int) $account->id())
      ->condition('ebay_listing_id', $listingId)
      ->execute();
  }

  private function didMigrationSucceed(array $response, string $listingId): bool {
    $responses = $response['responses'] ?? [];
    if (!is_array($responses)) {
      return FALSE;
    }

    foreach ($responses as $listingResponse) {
      if (!is_array($listingResponse)) {
        continue;
      }

      if (($listingResponse['listingId'] ?? NULL) !== $listingId) {
        continue;
      }

      return (int) ($listingResponse['statusCode'] ?? 0) === 200;
    }

    return FALSE;
  }

  private function normalizeNullableString(string $value): ?string {
    $trimmed = trim($value);
    return $trimmed === '' ? NULL : $trimmed;
  }

}
