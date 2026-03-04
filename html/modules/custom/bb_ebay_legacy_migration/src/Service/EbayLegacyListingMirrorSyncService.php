<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\Core\Database\Connection;

final class EbayLegacyListingMirrorSyncService {

  public function __construct(
    private readonly EbayTradingLegacyClient $tradingClient,
    private readonly Connection $database,
  ) {}

  public function syncAccount(EbayAccount $account, int $entriesPerPage = 200): array {
    $accountId = (int) $account->id();
    $syncStartedAt = $this->resolveSyncMarker($accountId);
    $pageNumber = 1;
    $totalPages = 1;
    $syncedCount = 0;

    do {
      $response = $this->tradingClient->listActiveListingsForAccount(
        $account,
        $pageNumber,
        $entriesPerPage,
      );

      $totalPages = (int) $response['total_pages'];

      foreach ($response['items'] as $item) {
        $this->upsertListingRow($accountId, $item, $syncStartedAt);
        $syncedCount++;
      }

      $pageNumber++;
    } while ($pageNumber <= $totalPages);

    $deletedCount = $this->deleteStaleRows($accountId, $syncStartedAt);

    return [
      'synced_count' => $syncedCount,
      'deleted_count' => $deletedCount,
      'pages' => $totalPages,
    ];
  }

  private function upsertListingRow(int $accountId, array $item, int $lastSeen): void {
    $fields = [
      'account_id' => $accountId,
      'ebay_listing_id' => (string) $item['ebay_listing_id'],
      'sku' => $item['sku'],
      'title' => $item['title'],
      'ebay_listing_started_at' => $item['ebay_listing_started_at'],
      'listing_status' => $item['listing_status'],
      'primary_category_id' => $item['primary_category_id'],
      'raw_xml' => $item['raw_xml'],
      'last_seen' => $lastSeen,
    ];

    $this->database
      ->merge('bb_ebay_legacy_listing')
      ->keys([
        'account_id' => $accountId,
        'ebay_listing_id' => (string) $item['ebay_listing_id'],
      ])
      ->fields($fields)
      ->execute();
  }

  private function deleteStaleRows(int $accountId, int $syncStartedAt): int {
    return (int) $this->database
      ->delete('bb_ebay_legacy_listing')
      ->condition('account_id', $accountId)
      ->condition('last_seen', $syncStartedAt, '<')
      ->execute();
  }

  private function resolveSyncMarker(int $accountId): int {
    $currentTime = time();
    $query = $this->database
      ->select('bb_ebay_legacy_listing', 'l')
      ->condition('account_id', $accountId);
    $query->addExpression('MAX(last_seen)', 'max_last_seen');

    $previousMarker = $query
      ->execute()
      ->fetchField();

    $previousMarker = $previousMarker === FALSE ? 0 : (int) $previousMarker;

    if ($currentTime > $previousMarker) {
      return $currentTime;
    }

    return $previousMarker + 1;
  }

}
