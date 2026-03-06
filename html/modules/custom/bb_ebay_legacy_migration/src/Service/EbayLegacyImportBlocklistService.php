<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

final class EbayLegacyImportBlocklistService {

  private const COLLECTION = 'bb_ebay_legacy_migration.import_blocked';

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly Connection $database,
  ) {}

  public function recordFailure(string $listingId, string $errorMessage): void {
    $normalizedListingId = trim($listingId);
    if ($normalizedListingId === '') {
      return;
    }

    $collection = $this->keyValueFactory->get(self::COLLECTION);
    $existing = $collection->get($normalizedListingId, []);
    $now = time();

    $status = $this->classifyFailureStatus($errorMessage);
    if ($status === 'already_migrated') {
      $this->clearFailure($normalizedListingId);
      return;
    }

    $record = [
      'listing_id' => $normalizedListingId,
      'first_failed_at' => (int) ($existing['first_failed_at'] ?? $now),
      'last_failed_at' => $now,
      'failure_count' => (int) ($existing['failure_count'] ?? 0) + 1,
      'status' => $status,
      'last_error_message' => $this->normalizeErrorMessage($errorMessage),
    ];

    $collection->set($normalizedListingId, $record);
  }

  public function clearFailure(string $listingId): void {
    $normalizedListingId = trim($listingId);
    if ($normalizedListingId === '') {
      return;
    }

    $this->keyValueFactory->get(self::COLLECTION)->delete($normalizedListingId);
  }

  public function markClearedForRetry(string $listingId): void {
    $normalizedListingId = trim($listingId);
    if ($normalizedListingId === '') {
      return;
    }

    $collection = $this->keyValueFactory->get(self::COLLECTION);
    $existing = $collection->get($normalizedListingId, []);
    if (!is_array($existing) || $existing === []) {
      return;
    }

    $existing['status'] = 'cleared';
    $existing['cleared_at'] = time();
    $collection->set($normalizedListingId, $existing);
  }

  /**
   * @return array<int,array{
   *   listing_id:string,
   *   title:?string,
   *   sku:?string,
   *   status:string,
   *   first_failed_at:int,
   *   last_failed_at:int,
   *   failure_count:int,
   *   last_error_message:string
   * }>
   */
  public function getFailuresForAccount(int $accountId): array {
    $collection = $this->keyValueFactory->get(self::COLLECTION);
    $all = $collection->getAll();
    if ($all === []) {
      return [];
    }

    $listingIds = array_values(array_filter(array_keys($all), static fn (string $listingId): bool => trim($listingId) !== ''));
    if ($listingIds === []) {
      return [];
    }

    $legacyRows = $this->loadLegacyRows($accountId, $listingIds);
    $rows = [];

    foreach ($listingIds as $listingId) {
      $record = is_array($all[$listingId] ?? NULL) ? $all[$listingId] : [];
      $legacy = $legacyRows[$listingId] ?? NULL;

      $rows[] = [
        'listing_id' => $listingId,
        'title' => $legacy['title'] ?? NULL,
        'sku' => $legacy['sku'] ?? NULL,
        'status' => $this->normalizeFailureStatus((string) ($record['status'] ?? ''), (string) ($record['last_error_message'] ?? '')),
        'first_failed_at' => (int) ($record['first_failed_at'] ?? 0),
        'last_failed_at' => (int) ($record['last_failed_at'] ?? 0),
        'failure_count' => (int) ($record['failure_count'] ?? 0),
        'last_error_message' => (string) ($record['last_error_message'] ?? 'Unknown import failure'),
      ];
    }

    usort($rows, static fn (array $a, array $b): int => $b['last_failed_at'] <=> $a['last_failed_at']);
    return $rows;
  }

  /**
   * @return string[]
   */
  public function getBlockedListingIdsForAccount(int $accountId): array {
    $rows = $this->getFailuresForAccount($accountId);
    $listingIds = [];

    foreach ($rows as $row) {
      if (($row['status'] ?? '') !== 'needs_manual_fix') {
        continue;
      }

      $listingId = trim((string) ($row['listing_id'] ?? ''));
      if ($listingId !== '') {
        $listingIds[] = $listingId;
      }
    }

    return array_values(array_unique($listingIds));
  }

  /**
   * Remove blocked rows that no longer need manual fixing.
   *
   * A row is pruned when its legacy listing is not ACTIVE (for example ended)
   * or no longer exists in the legacy mirror for this account.
   */
  public function pruneNonActiveFailuresForAccount(int $accountId): int {
    $collection = $this->keyValueFactory->get(self::COLLECTION);
    $all = $collection->getAll();
    if ($all === []) {
      return 0;
    }

    $listingIds = array_values(array_filter(array_keys($all), static fn (string $listingId): bool => trim($listingId) !== ''));
    if ($listingIds === []) {
      return 0;
    }

    $legacyRows = $this->loadLegacyRows($accountId, $listingIds);
    $deleted = 0;

    foreach ($listingIds as $listingId) {
      $legacy = $legacyRows[$listingId] ?? NULL;
      if (!is_array($legacy)) {
        $collection->delete($listingId);
        $deleted++;
        continue;
      }

      $legacyStatus = $this->normalizeNullableString($legacy['listing_status'] ?? NULL);
      if ($legacyStatus !== NULL && strtoupper($legacyStatus) !== 'ACTIVE') {
        $collection->delete($listingId);
        $deleted++;
      }
    }

    return $deleted;
  }

  private function normalizeErrorMessage(string $errorMessage): string {
    $normalized = trim($errorMessage);
    if ($normalized === '') {
      return 'Unknown import failure';
    }

    if (mb_strlen($normalized) <= 1000) {
      return $normalized;
    }

    return mb_substr($normalized, 0, 997) . '...';
  }

  private function classifyFailureStatus(string $errorMessage): string {
    $normalized = strtolower($errorMessage);

    if (str_contains($normalized, 'already migrated')) {
      return 'already_migrated';
    }

    if (str_contains($normalized, 'a system error has occurred')
      || str_contains($normalized, 'dependent service failure')
      || str_contains($normalized, '"statuscode":500')
      || str_contains($normalized, '"errorid":25001')) {
      return 'retry_next_run';
    }

    return 'needs_manual_fix';
  }

  private function normalizeFailureStatus(string $storedStatus, string $errorMessage): string {
    $normalizedStoredStatus = trim($storedStatus);
    if ($normalizedStoredStatus !== '') {
      return $normalizedStoredStatus;
    }

    return $this->classifyFailureStatus($errorMessage);
  }

  /**
   * @param string[] $listingIds
   *
   * @return array<string,array{title:?string,sku:?string,listing_status:?string}>
   */
  private function loadLegacyRows(int $accountId, array $listingIds): array {
    $query = $this->database->select('bb_ebay_legacy_listing', 'legacy')
      ->fields('legacy', ['ebay_listing_id', 'title', 'sku', 'listing_status'])
      ->condition('legacy.account_id', $accountId)
      ->condition('legacy.ebay_listing_id', $listingIds, 'IN');

    $result = $query->execute()->fetchAll();
    $rows = [];

    foreach ($result as $row) {
      $listingId = (string) ($row->ebay_listing_id ?? '');
      if ($listingId === '') {
        continue;
      }

      $rows[$listingId] = [
        'title' => $this->normalizeNullableString($row->title ?? NULL),
        'sku' => $this->normalizeNullableString($row->sku ?? NULL),
        'listing_status' => strtoupper((string) ($this->normalizeNullableString($row->listing_status ?? NULL) ?? '')),
      ];
    }

    return $rows;
  }

  private function normalizeNullableString(mixed $value): ?string {
    if (!is_scalar($value)) {
      return NULL;
    }

    $normalized = trim((string) $value);
    return $normalized === '' ? NULL : $normalized;
  }

}
