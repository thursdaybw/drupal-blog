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
use Throwable;

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
   *   migration_attempts:int,
   *   migrate_response:?array
   * }
   */
  public function prepareAndMigrateListingId(string $listingId, bool $resyncMirror = TRUE): array {
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
        'migration_attempts' => 0,
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

    $migrationResult = $this->migrateListingIdWithRetry($account, $normalizedListingId);
    $response = $migrationResult['response'];
    $migrationSucceeded = $this->didMigrationSucceed($response, $normalizedListingId);

    if ($resyncMirror && $migrationSucceeded && $preparedSku !== NULL) {
      $this->syncMirrorsForSku($account, $preparedSku);
    }

    return [
      'listing_id' => $normalizedListingId,
      'status' => $migrationSucceeded ? 'migrated' : 'failed_migration',
      'previous_sku' => $previousSku,
      'prepared_sku' => $preparedSku,
      'sku_change_reason' => $skuChangeReason,
      'migration_attempts' => $migrationResult['attempts'],
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

  private function syncMirrorsForSku(EbayAccount $account, string $sku): void {
    $normalizedSku = trim($sku);
    if ($normalizedSku === '') {
      return;
    }

    $this->inventoryMirrorSyncService->syncSku($account, $normalizedSku);
    $this->offerMirrorSyncService->syncSku($account, $normalizedSku);
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

  public function resyncMirrorsForPrimaryAccount(): void {
    $account = $this->accountManager->loadPrimaryAccount();
    $this->resyncMirror($account);
  }

  /**
   * @param string[] $skus
   */
  public function syncMirrorsForPrimaryAccountSkus(array $skus): array {
    $account = $this->accountManager->loadPrimaryAccount();
    $normalizedSkus = array_values(array_unique(array_filter(
      array_map(static fn (mixed $sku): string => is_scalar($sku) ? trim((string) $sku) : '', $skus),
      static fn (string $sku): bool => $sku !== ''
    )));

    $result = [
      'synced_skus' => [],
      'failed_skus' => [],
    ];

    foreach ($normalizedSkus as $sku) {
      $syncSucceeded = $this->syncMirrorsForSkuWithRetry($account, $sku);
      if ($syncSucceeded) {
        $result['synced_skus'][] = $sku;
      }
      else {
        $result['failed_skus'][] = $sku;
      }
    }

    return $result;
  }

  private function syncMirrorsForSkuWithRetry(EbayAccount $account, string $sku): bool {
    $attemptDelaysSeconds = [0, 1, 3, 7];

    foreach ($attemptDelaysSeconds as $attemptIndex => $delaySeconds) {
      if ($delaySeconds > 0) {
        sleep($delaySeconds);
      }

      try {
        $this->syncMirrorsForSku($account, $sku);
        return TRUE;
      }
      catch (Throwable $e) {
        if (!$this->isRetryableSellSystemError($e)) {
          return FALSE;
        }

        if ($attemptIndex === array_key_last($attemptDelaysSeconds)) {
          return FALSE;
        }
      }
    }

    return FALSE;
  }

  private function isRetryableSellSystemError(Throwable $e): bool {
    $message = $e->getMessage();

    if (!str_contains($message, '"errorId":25001')) {
      return FALSE;
    }

    return str_contains($message, '"category":"System"')
      || str_contains($message, '"category":"REQUEST"')
      || str_contains($message, '"category":"Request"');
  }

  /**
   * @return array{response:array,attempts:int}
   */
  private function migrateListingIdWithRetry(EbayAccount $account, string $listingId): array {
    $attemptDelaysSeconds = [0, 1, 3, 7];
    $lastResponse = [];
    $attempts = 0;

    foreach ($attemptDelaysSeconds as $attemptIndex => $delaySeconds) {
      $attempts++;
      if ($delaySeconds > 0) {
        sleep($delaySeconds);
      }

      try {
        $response = $this->sellApiClient->bulkMigrateListingsForAccount($account, [$listingId]);
        $lastResponse = is_array($response) ? $response : [];
      }
      catch (Throwable $e) {
        if (!$this->isRetryableSellSystemError($e)) {
          throw $e;
        }

        if ($attemptIndex === array_key_last($attemptDelaysSeconds)) {
          throw $e;
        }

        continue;
      }

      if ($this->didMigrationSucceed($lastResponse, $listingId)) {
        return [
          'response' => $lastResponse,
          'attempts' => $attempts,
        ];
      }

      if (!$this->isRetryableMigrationResponse($lastResponse, $listingId)) {
        return [
          'response' => $lastResponse,
          'attempts' => $attempts,
        ];
      }

      if ($attemptIndex === array_key_last($attemptDelaysSeconds)) {
        return [
          'response' => $lastResponse,
          'attempts' => $attempts,
        ];
      }
    }

    return [
      'response' => $lastResponse,
      'attempts' => $attempts,
    ];
  }

  private function isRetryableMigrationResponse(array $response, string $listingId): bool {
    $responses = $response['responses'] ?? NULL;
    if (!is_array($responses)) {
      return FALSE;
    }

    foreach ($responses as $listingResponse) {
      if (!is_array($listingResponse)) {
        continue;
      }

      if ((string) ($listingResponse['listingId'] ?? '') !== $listingId) {
        continue;
      }

      $statusCode = (int) ($listingResponse['statusCode'] ?? 0);
      if ($statusCode < 500) {
        return FALSE;
      }

      $errors = $listingResponse['errors'] ?? NULL;
      if (!is_array($errors) || $errors === []) {
        return FALSE;
      }

      foreach ($errors as $error) {
        if (!is_array($error)) {
          continue;
        }

        $errorId = (int) ($error['errorId'] ?? 0);
        if ($errorId !== 25001) {
          continue;
        }

        $category = strtoupper((string) ($error['category'] ?? ''));
        if ($category === 'SYSTEM' || $category === 'REQUEST') {
          return TRUE;
        }
      }

      return FALSE;
    }

    return FALSE;
  }

}
