<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Report;

use Drupal\Core\Database\Connection;

/**
 * Read model query for oldest currently published eBay stock.
 */
final class EbayStockCullReportQuery {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * @return \Drupal\ai_listing\Report\EbayStockCullReportRow[]
   */
  public function fetchRows(
    int $limit = 250,
    ?string $listingType = NULL,
    ?float $maxPrice = NULL,
    ?int $listedBeforeTimestamp = NULL,
  ): array {
    $query = $this->buildBaseQuery($listingType, $maxPrice, $listedBeforeTimestamp);
    $query->fields('l', ['id', 'listing_type', 'ebay_title', 'price', 'storage_location']);
    $query->fields('p', ['inventory_sku_value', 'marketplace_listing_id', 'source', 'marketplace_started_at', 'published_at']);
    $query->orderBy('effective_listed_at', 'ASC');
    $query->orderBy('l.price', 'ASC');
    $query->orderBy('l.id', 'ASC');
    $query->range(0, $limit);

    $rows = [];
    foreach ($query->execute()->fetchAllAssoc('id') as $record) {
      $rows[] = new EbayStockCullReportRow(
        (int) $record->id,
        (string) $record->listing_type,
        trim((string) $record->ebay_title),
        (string) $record->price,
        trim((string) ($record->storage_location ?? '')),
        trim((string) ($record->inventory_sku_value ?? '')),
        (string) $record->marketplace_listing_id,
        (string) $record->source,
        $record->marketplace_started_at !== NULL ? (int) $record->marketplace_started_at : NULL,
        $record->published_at !== NULL ? (int) $record->published_at : NULL,
      );
    }

    return $rows;
  }

  public function countRows(
    ?string $listingType = NULL,
    ?float $maxPrice = NULL,
    ?int $listedBeforeTimestamp = NULL,
  ): int {
    $query = $this->buildBaseQuery($listingType, $maxPrice, $listedBeforeTimestamp);
    $result = $query->countQuery()->execute()->fetchField();
    return is_numeric($result) ? (int) $result : 0;
  }

  private function buildBaseQuery(
    ?string $listingType,
    ?float $maxPrice,
    ?int $listedBeforeTimestamp,
  ) {
    $query = $this->database->select('ai_marketplace_publication', 'p');
    $query->innerJoin('bb_ai_listing', 'l', 'l.id = p.listing');
    $query->addExpression('COALESCE(p.marketplace_started_at, p.published_at)', 'effective_listed_at');
    $query->condition('p.marketplace_key', 'ebay');
    $query->condition('p.status', 'published');
    $query->where('COALESCE(p.marketplace_started_at, p.published_at) IS NOT NULL');
    if ($listingType !== NULL && $listingType !== '') {
      $query->condition('l.listing_type', $listingType);
    }
    if ($maxPrice !== NULL) {
      $query->condition('l.price', (string) $maxPrice, '<=');
    }
    if ($listedBeforeTimestamp !== NULL) {
      $query->where('COALESCE(p.marketplace_started_at, p.published_at) <= :listed_before_timestamp', [
        ':listed_before_timestamp' => $listedBeforeTimestamp,
      ]);
    }

    return $query;
  }

}
