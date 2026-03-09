<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

final class LegacyTableSyncService {

  public function __construct(
    private readonly Connection $database,
    private readonly LegacyPayloadRuntimeStore $runtimeStore,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array{
   *   bb_ebay_legacy_listing_link: array<int, array<string, mixed>>,
   *   bb_ebay_legacy_listing: array<int, array<string, mixed>>
   * }
   */
  public function exportLegacyRowsForListing(int $listingId): array {
    $empty = [
      'bb_ebay_legacy_listing_link' => [],
      'bb_ebay_legacy_listing' => [],
    ];

    if (!$this->database->schema()->tableExists('bb_ebay_legacy_listing_link')) {
      return $empty;
    }

    $links = [];
    $result = $this->database->select('bb_ebay_legacy_listing_link', 'l')
      ->fields('l')
      ->condition('listing', $listingId)
      ->execute();

    foreach ($result as $row) {
      $values = (array) $row;
      unset($values['id']);
      $links[] = $values;
    }

    if ($links === [] || !$this->database->schema()->tableExists('bb_ebay_legacy_listing')) {
      return [
        'bb_ebay_legacy_listing_link' => $links,
        'bb_ebay_legacy_listing' => [],
      ];
    }

    $listings = [];
    foreach ($links as $link) {
      $accountId = (int) ($link['account_id'] ?? 0);
      $ebayListingId = (string) ($link['ebay_listing_id'] ?? '');
      if ($accountId <= 0 || $ebayListingId === '') {
        continue;
      }

      $legacy = $this->database->select('bb_ebay_legacy_listing', 'x')
        ->fields('x')
        ->condition('account_id', $accountId)
        ->condition('ebay_listing_id', $ebayListingId)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if (!is_array($legacy)) {
        continue;
      }

      unset($legacy['id']);
      $listings[] = $legacy;
    }

    return [
      'bb_ebay_legacy_listing_link' => $links,
      'bb_ebay_legacy_listing' => $listings,
    ];
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function stageImportPayload(string $listingUuid, array $payload): void {
    $this->runtimeStore->setPayload($listingUuid, $payload);
  }

  /**
   * @return array{legacy_link_upserts:int,legacy_listing_upserts:int}
   */
  public function applyPayloadForListing(string $listingUuid, int $listingId): array {
    $payload = $this->runtimeStore->getPayload($listingUuid);
    if (!is_array($payload)) {
      return [
        'legacy_link_upserts' => 0,
        'legacy_listing_upserts' => 0,
      ];
    }

    $summary = [
      'legacy_link_upserts' => 0,
      'legacy_listing_upserts' => 0,
    ];

    $this->database->startTransaction();

    if ($this->database->schema()->tableExists('bb_ebay_legacy_listing_link')) {
      $summary['legacy_link_upserts'] = $this->upsertLegacyLinkRows($payload, $listingId);
    }

    if ($this->database->schema()->tableExists('bb_ebay_legacy_listing')) {
      $summary['legacy_listing_upserts'] = $this->upsertLegacyListingRows($payload);
    }

    $this->runtimeStore->clearPayload($listingUuid);

    return $summary;
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function upsertLegacyLinkRows(array $payload, int $listingId): int {
    $rows = $payload['bb_ebay_legacy_listing_link'] ?? [];
    if (!is_array($rows)) {
      return 0;
    }

    $upserts = 0;
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $ebayListingId = (string) ($row['ebay_listing_id'] ?? '');
      if ($ebayListingId === '') {
        continue;
      }

      $values = [
        'listing' => $listingId,
        'account_id' => (int) ($row['account_id'] ?? 0),
        'origin_type' => (string) ($row['origin_type'] ?? 'legacy_ebay_migrated'),
        'ebay_listing_id' => $ebayListingId,
        'ebay_listing_started_at' => isset($row['ebay_listing_started_at']) ? (int) $row['ebay_listing_started_at'] : NULL,
        'source_sku' => isset($row['source_sku']) ? (string) $row['source_sku'] : NULL,
        'created' => isset($row['created']) ? (int) $row['created'] : $this->time->getRequestTime(),
        'changed' => $this->time->getRequestTime(),
      ];

      $this->database->merge('bb_ebay_legacy_listing_link')
        ->keys([
          'ebay_listing_id' => $values['ebay_listing_id'],
        ])
        ->fields($values)
        ->execute();

      $upserts++;
    }

    return $upserts;
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function upsertLegacyListingRows(array $payload): int {
    $rows = $payload['bb_ebay_legacy_listing'] ?? [];
    if (!is_array($rows)) {
      return 0;
    }

    $upserts = 0;
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $accountId = (int) ($row['account_id'] ?? 0);
      $ebayListingId = (string) ($row['ebay_listing_id'] ?? '');
      if ($accountId <= 0 || $ebayListingId === '') {
        continue;
      }

      $values = [
        'account_id' => $accountId,
        'ebay_listing_id' => $ebayListingId,
        'sku' => isset($row['sku']) ? (string) $row['sku'] : NULL,
        'title' => isset($row['title']) ? (string) $row['title'] : NULL,
        'ebay_listing_started_at' => isset($row['ebay_listing_started_at']) ? (int) $row['ebay_listing_started_at'] : NULL,
        'listing_status' => isset($row['listing_status']) ? (string) $row['listing_status'] : NULL,
        'primary_category_id' => isset($row['primary_category_id']) ? (string) $row['primary_category_id'] : NULL,
        'raw_xml' => isset($row['raw_xml']) ? (string) $row['raw_xml'] : NULL,
        'last_seen' => isset($row['last_seen']) ? (int) $row['last_seen'] : $this->time->getRequestTime(),
      ];

      $this->database->merge('bb_ebay_legacy_listing')
        ->keys([
          'account_id' => $accountId,
          'ebay_listing_id' => $ebayListingId,
        ])
        ->fields($values)
        ->execute();

      $upserts++;
    }

    return $upserts;
  }

}
