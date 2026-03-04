<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Service;

use Drupal\Core\Database\Connection;

final class EbayMirrorAuditService {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public function countMirroredInventoryRows(int $accountId): int {
    return (int) $this->database->select('bb_ebay_inventory_item', 'inventory')
      ->condition('account_id', $accountId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function countMirroredOfferRows(int $accountId): int {
    return (int) $this->database->select('bb_ebay_offer', 'offer')
      ->condition('account_id', $accountId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function countLegacyListingRows(int $accountId): int {
    return (int) $this->database->select('bb_ebay_legacy_listing', 'legacy')
      ->condition('account_id', $accountId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Find local published eBay listings whose SKU is missing from the mirror.
   *
   * @return array<int,array{listing_id:int,ebay_title:?string,storage_location:?string,sku:string,marketplace_listing_id:?string}>
   */
  public function findPublishedListingsMissingMirroredInventory(int $accountId): array {
    $query = $this->database->select('ai_marketplace_publication', 'publication');
    $query->leftJoin(
      'bb_ebay_inventory_item',
      'inventory',
      'inventory.account_id = :account_id AND inventory.sku = publication.inventory_sku_value',
      [':account_id' => $accountId]
    );
    $query->leftJoin('bb_ai_listing', 'listing', 'listing.id = publication.listing');

    $query->fields('publication', ['listing', 'inventory_sku_value', 'marketplace_listing_id']);
    $query->fields('listing', ['ebay_title', 'storage_location']);
    $query->condition('publication.marketplace_key', 'ebay');
    $query->condition('publication.status', 'published');
    $query->isNull('inventory.sku');
    $query->orderBy('publication.listing', 'ASC');

    $results = $query->execute()->fetchAllAssoc('listing');
    $rows = [];

    foreach ($results as $listingId => $result) {
      $rows[] = [
        'listing_id' => (int) $listingId,
        'ebay_title' => $this->normalizeNullableString($result->ebay_title ?? NULL),
        'storage_location' => $this->normalizeNullableString($result->storage_location ?? NULL),
        'sku' => (string) ($result->inventory_sku_value ?? ''),
        'marketplace_listing_id' => $this->normalizeNullableString($result->marketplace_listing_id ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find local published eBay listings whose SKU is missing from the offer mirror.
   *
   * @return array<int,array{listing_id:int,ebay_title:?string,storage_location:?string,sku:string,marketplace_listing_id:?string}>
   */
  public function findPublishedListingsMissingMirroredOffer(int $accountId): array {
    $query = $this->database->select('ai_marketplace_publication', 'publication');
    $query->leftJoin(
      'bb_ebay_offer',
      'offer',
      'offer.account_id = :account_id AND offer.sku = publication.inventory_sku_value',
      [':account_id' => $accountId]
    );
    $query->leftJoin('bb_ai_listing', 'listing', 'listing.id = publication.listing');

    $query->fields('publication', ['listing', 'inventory_sku_value', 'marketplace_listing_id']);
    $query->fields('listing', ['ebay_title', 'storage_location']);
    $query->condition('publication.marketplace_key', 'ebay');
    $query->condition('publication.status', 'published');
    $query->isNull('offer.offer_id');
    $query->orderBy('publication.listing', 'ASC');

    $results = $query->execute()->fetchAllAssoc('listing');
    $rows = [];

    foreach ($results as $listingId => $result) {
      $rows[] = [
        'listing_id' => (int) $listingId,
        'ebay_title' => $this->normalizeNullableString($result->ebay_title ?? NULL),
        'storage_location' => $this->normalizeNullableString($result->storage_location ?? NULL),
        'sku' => (string) ($result->inventory_sku_value ?? ''),
        'marketplace_listing_id' => $this->normalizeNullableString($result->marketplace_listing_id ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find mirrored inventory rows that have no local published listing.
   *
   * In the current system, a local listing is treated as present when Drupal
   * has a published eBay publication row for the mirrored SKU.
   *
   * @return array<int,array{sku:string,title:?string,available_quantity:?int,condition:?string}>
   */
  public function findMirroredInventoryMissingLocalListing(int $accountId): array {
    $query = $this->database->select('bb_ebay_inventory_item', 'inventory');
    $query->leftJoin(
      'ai_marketplace_publication',
      'publication',
      'publication.marketplace_key = :marketplace_key AND publication.status = :status AND publication.inventory_sku_value = inventory.sku',
      [
        ':marketplace_key' => 'ebay',
        ':status' => 'published',
      ]
    );

    $query->fields('inventory', ['sku', 'title', 'available_quantity', 'condition']);
    $query->condition('inventory.account_id', $accountId);
    $query->isNull('publication.id');
    $query->orderBy('inventory.sku', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $rows[] = [
        'sku' => (string) ($result->sku ?? ''),
        'title' => $this->normalizeNullableString($result->title ?? NULL),
        'available_quantity' => $this->normalizeNullableInt($result->available_quantity ?? NULL),
        'condition' => $this->normalizeNullableString($result->condition ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find mirrored offers that have no local published listing.
   *
   * In the current system, a local listing is treated as present when Drupal
   * has a published eBay publication row for the mirrored SKU.
   *
   * @return array<int,array{offer_id:string,sku:string,listing_id:?string,listing_status:?string,status:?string}>
   */
  public function findMirroredOffersMissingLocalListing(int $accountId): array {
    $query = $this->database->select('bb_ebay_offer', 'offer');
    $query->leftJoin(
      'ai_marketplace_publication',
      'publication',
      'publication.marketplace_key = :marketplace_key AND publication.status = :status AND publication.inventory_sku_value = offer.sku',
      [
        ':marketplace_key' => 'ebay',
        ':status' => 'published',
      ]
    );

    $query->fields('offer', ['offer_id', 'sku', 'listing_id', 'listing_status', 'status']);
    $query->condition('offer.account_id', $accountId);
    $query->isNull('publication.id');
    $query->orderBy('offer.sku', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $rows[] = [
        'offer_id' => (string) ($result->offer_id ?? ''),
        'sku' => (string) ($result->sku ?? ''),
        'listing_id' => $this->normalizeNullableString($result->listing_id ?? NULL),
        'listing_status' => $this->normalizeNullableString($result->listing_status ?? NULL),
        'status' => $this->normalizeNullableString($result->status ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find mirrored SKUs whose embedded listing identifier disagrees with Drupal.
   *
   * This is more opinionated than the presence/absence audits. It reads the
   * identifier hidden inside the SKU, resolves that back to a local listing,
   * then checks whether Drupal's current publication link agrees with it.
   *
   * Resolution order:
   * - new SKUs: match `listing_code`
   * - legacy SKUs: fall back to numeric entity ID
   *
   * @return array<int,array{
   *   sku:string,
   *   sku_identifier:?string,
   *   resolved_listing_id:?int,
   *   resolved_listing_code:?string,
   *   resolved_ebay_title:?string,
   *   publication_listing_id:?int,
   *   publication_marketplace_listing_id:?string,
   *   offer_id:?string,
   *   offer_status:?string,
   *   reason:string
   * }>
   */
  public function findSkuLinkMismatches(int $accountId): array {
    $query = $this->database->select('bb_ebay_inventory_item', 'inventory');
    $query->leftJoin(
      'bb_ebay_offer',
      'offer',
      'offer.account_id = inventory.account_id AND offer.sku = inventory.sku'
    );
    $query->fields('inventory', ['sku']);
    $query->fields('offer', ['offer_id', 'status']);
    $query->condition('inventory.account_id', $accountId);
    $query->orderBy('inventory.sku', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $sku = (string) ($result->sku ?? '');
      $skuIdentifier = $this->extractSkuIdentifier($sku);

      if ($skuIdentifier === NULL) {
        $rows[] = [
          'sku' => $sku,
          'sku_identifier' => NULL,
          'resolved_listing_id' => NULL,
          'resolved_listing_code' => NULL,
          'resolved_ebay_title' => NULL,
          'publication_listing_id' => NULL,
          'publication_marketplace_listing_id' => NULL,
          'offer_id' => $this->normalizeNullableString($result->offer_id ?? NULL),
          'offer_status' => $this->normalizeNullableString($result->status ?? NULL),
          'reason' => 'sku_identifier_missing',
        ];
        continue;
      }

      $resolvedListing = $this->resolveListingFromSkuIdentifier($skuIdentifier);
      $publication = $this->loadPublishedPublicationBySku($sku);

      if ($resolvedListing === NULL) {
        $rows[] = [
          'sku' => $sku,
          'sku_identifier' => $skuIdentifier,
          'resolved_listing_id' => NULL,
          'resolved_listing_code' => NULL,
          'resolved_ebay_title' => NULL,
          'publication_listing_id' => $publication['listing_id'] ?? NULL,
          'publication_marketplace_listing_id' => $publication['marketplace_listing_id'] ?? NULL,
          'offer_id' => $this->normalizeNullableString($result->offer_id ?? NULL),
          'offer_status' => $this->normalizeNullableString($result->status ?? NULL),
          'reason' => 'sku_identifier_does_not_resolve',
        ];
        continue;
      }

      if ($publication === NULL) {
        $rows[] = [
          'sku' => $sku,
          'sku_identifier' => $skuIdentifier,
          'resolved_listing_id' => $resolvedListing['id'],
          'resolved_listing_code' => $resolvedListing['listing_code'],
          'resolved_ebay_title' => $resolvedListing['ebay_title'],
          'publication_listing_id' => NULL,
          'publication_marketplace_listing_id' => NULL,
          'offer_id' => $this->normalizeNullableString($result->offer_id ?? NULL),
          'offer_status' => $this->normalizeNullableString($result->status ?? NULL),
          'reason' => 'missing_local_publication_link',
        ];
        continue;
      }

      if ($publication['listing_id'] !== $resolvedListing['id']) {
        $rows[] = [
          'sku' => $sku,
          'sku_identifier' => $skuIdentifier,
          'resolved_listing_id' => $resolvedListing['id'],
          'resolved_listing_code' => $resolvedListing['listing_code'],
          'resolved_ebay_title' => $resolvedListing['ebay_title'],
          'publication_listing_id' => $publication['listing_id'],
          'publication_marketplace_listing_id' => $publication['marketplace_listing_id'],
          'offer_id' => $this->normalizeNullableString($result->offer_id ?? NULL),
          'offer_status' => $this->normalizeNullableString($result->status ?? NULL),
          'reason' => 'publication_points_to_different_listing',
        ];
      }
    }

    return $rows;
  }

  /**
   * Find local listings that resolve from more than one mirrored inventory SKU.
   *
   * @return array<int,array{
   *   listing_id:int,
   *   listing_code:?string,
   *   ebay_title:?string,
   *   mirrored_sku_count:int,
   *   mirrored_skus:string[]
   * }>
   */
  public function findListingsWithMultipleMirroredInventorySkus(int $accountId): array {
    $query = $this->database->select('bb_ebay_inventory_item', 'inventory');
    $query->fields('inventory', ['sku']);
    $query->condition('inventory.account_id', $accountId);
    $query->orderBy('inventory.sku', 'ASC');

    $results = $query->execute()->fetchAll();
    $rowsByListing = [];

    foreach ($results as $result) {
      $sku = (string) ($result->sku ?? '');
      $skuIdentifier = $this->extractSkuIdentifier($sku);
      if ($skuIdentifier === NULL) {
        continue;
      }

      $resolvedListing = $this->resolveListingFromSkuIdentifier($skuIdentifier);
      if ($resolvedListing === NULL) {
        continue;
      }

      $listingId = $resolvedListing['id'];
      if (!isset($rowsByListing[$listingId])) {
        $rowsByListing[$listingId] = [
          'listing_id' => $listingId,
          'listing_code' => $resolvedListing['listing_code'],
          'ebay_title' => $resolvedListing['ebay_title'],
          'mirrored_sku_count' => 0,
          'mirrored_skus' => [],
        ];
      }

      if (!in_array($sku, $rowsByListing[$listingId]['mirrored_skus'], TRUE)) {
        $rowsByListing[$listingId]['mirrored_skus'][] = $sku;
        $rowsByListing[$listingId]['mirrored_sku_count']++;
      }
    }

    $rows = array_values(array_filter(
      $rowsByListing,
      static fn (array $row): bool => $row['mirrored_sku_count'] > 1
    ));

    usort(
      $rows,
      static fn (array $left, array $right): int => $left['listing_id'] <=> $right['listing_id']
    );

    return $rows;
  }

  /**
   * Find local listings that resolve from more than one mirrored offer.
   *
   * @return array<int,array{
   *   listing_id:int,
   *   listing_code:?string,
   *   ebay_title:?string,
   *   mirrored_offer_count:int,
   *   mirrored_offers:string[],
   *   mirrored_skus:string[]
   * }>
   */
  public function findListingsWithMultipleMirroredOffers(int $accountId): array {
    $query = $this->database->select('bb_ebay_offer', 'offer');
    $query->fields('offer', ['offer_id', 'sku']);
    $query->condition('offer.account_id', $accountId);
    $query->orderBy('offer.sku', 'ASC');

    $results = $query->execute()->fetchAll();
    $rowsByListing = [];

    foreach ($results as $result) {
      $sku = (string) ($result->sku ?? '');
      $skuIdentifier = $this->extractSkuIdentifier($sku);
      if ($skuIdentifier === NULL) {
        continue;
      }

      $resolvedListing = $this->resolveListingFromSkuIdentifier($skuIdentifier);
      if ($resolvedListing === NULL) {
        continue;
      }

      $listingId = $resolvedListing['id'];
      if (!isset($rowsByListing[$listingId])) {
        $rowsByListing[$listingId] = [
          'listing_id' => $listingId,
          'listing_code' => $resolvedListing['listing_code'],
          'ebay_title' => $resolvedListing['ebay_title'],
          'mirrored_offer_count' => 0,
          'mirrored_offers' => [],
          'mirrored_skus' => [],
        ];
      }

      $offerId = (string) ($result->offer_id ?? '');
      if ($offerId !== '' && !in_array($offerId, $rowsByListing[$listingId]['mirrored_offers'], TRUE)) {
        $rowsByListing[$listingId]['mirrored_offers'][] = $offerId;
        $rowsByListing[$listingId]['mirrored_offer_count']++;
      }

      if ($sku !== '' && !in_array($sku, $rowsByListing[$listingId]['mirrored_skus'], TRUE)) {
        $rowsByListing[$listingId]['mirrored_skus'][] = $sku;
      }
    }

    $rows = array_values(array_filter(
      $rowsByListing,
      static fn (array $row): bool => $row['mirrored_offer_count'] > 1
    ));

    usort(
      $rows,
      static fn (array $left, array $right): int => $left['listing_id'] <=> $right['listing_id']
    );

    return $rows;
  }

  /**
   * Find legacy listings that do not yet exist in the Sell offer mirror.
   *
   * @return array<int,array{
   *   ebay_listing_id:string,
   *   sku:?string,
   *   title:?string,
   *   ebay_listing_started_at:?int,
   *   listing_status:?string
   * }>
   */
  public function findLegacyListingsMissingMirroredSellOffer(int $accountId): array {
    $query = $this->database->select('bb_ebay_legacy_listing', 'legacy');
    $query->leftJoin(
      'bb_ebay_offer',
      'offer',
      'offer.account_id = legacy.account_id AND offer.listing_id = legacy.ebay_listing_id'
    );

    $query->fields('legacy', [
      'ebay_listing_id',
      'sku',
      'title',
      'ebay_listing_started_at',
      'listing_status',
    ]);
    $query->condition('legacy.account_id', $accountId);
    $query->isNull('offer.offer_id');
    $query->orderBy('legacy.ebay_listing_id', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $rows[] = [
        'ebay_listing_id' => (string) ($result->ebay_listing_id ?? ''),
        'sku' => $this->normalizeNullableString($result->sku ?? NULL),
        'title' => $this->normalizeNullableString($result->title ?? NULL),
        'ebay_listing_started_at' => $this->normalizeNullableInt($result->ebay_listing_started_at ?? NULL),
        'listing_status' => $this->normalizeNullableString($result->listing_status ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find legacy listings that are already visible in the Sell offer mirror.
   *
   * @return array<int,array{
   *   ebay_listing_id:string,
   *   sku:?string,
   *   title:?string,
   *   ebay_listing_started_at:?int,
   *   listing_status:?string,
   *   mirrored_offer_id:string,
   *   mirrored_offer_status:?string
   * }>
   */
  public function findLegacyListingsWithMirroredSellOffer(int $accountId): array {
    $query = $this->database->select('bb_ebay_legacy_listing', 'legacy');
    $query->innerJoin(
      'bb_ebay_offer',
      'offer',
      'offer.account_id = legacy.account_id AND offer.listing_id = legacy.ebay_listing_id'
    );

    $query->fields('legacy', [
      'ebay_listing_id',
      'sku',
      'title',
      'ebay_listing_started_at',
      'listing_status',
    ]);
    $query->fields('offer', ['offer_id', 'status']);
    $query->condition('legacy.account_id', $accountId);
    $query->orderBy('legacy.ebay_listing_id', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $rows[] = [
        'ebay_listing_id' => (string) ($result->ebay_listing_id ?? ''),
        'sku' => $this->normalizeNullableString($result->sku ?? NULL),
        'title' => $this->normalizeNullableString($result->title ?? NULL),
        'ebay_listing_started_at' => $this->normalizeNullableInt($result->ebay_listing_started_at ?? NULL),
        'listing_status' => $this->normalizeNullableString($result->listing_status ?? NULL),
        'mirrored_offer_id' => (string) ($result->offer_id ?? ''),
        'mirrored_offer_status' => $this->normalizeNullableString($result->status ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find legacy listings whose SKU is shared by more than one legacy listing.
   *
   * These rows are not clean migration candidates because Sell inventory is
   * keyed by SKU. If more than one legacy listing shares the same SKU, the
   * migration path needs extra handling first.
   *
   * @return array<int,array{
   *   sku:string,
   *   legacy_listing_count:int,
   *   ebay_listing_ids:string[],
   *   titles:string[],
   *   listing_statuses:string[]
   * }>
   */
  public function findLegacyListingsWithDuplicateSku(int $accountId): array {
    $query = $this->database->select('bb_ebay_legacy_listing', 'legacy');
    $query->addExpression('COUNT(*)', 'legacy_listing_count');
    $query->fields('legacy', ['sku']);
    $query->condition('legacy.account_id', $accountId);
    $query->isNotNull('legacy.sku');
    $query->where("TRIM(legacy.sku) <> ''");
    $query->groupBy('legacy.sku');
    $query->having('COUNT(*) > 1');
    $query->orderBy('legacy.sku', 'ASC');

    $duplicateSkuResults = $query->execute()->fetchAll();
    $rows = [];

    foreach ($duplicateSkuResults as $duplicateSkuResult) {
      $sku = (string) ($duplicateSkuResult->sku ?? '');
      $listingRows = $this->database->select('bb_ebay_legacy_listing', 'legacy')
        ->fields('legacy', ['ebay_listing_id', 'title', 'listing_status'])
        ->condition('account_id', $accountId)
        ->condition('sku', $sku)
        ->orderBy('ebay_listing_id', 'ASC')
        ->execute()
        ->fetchAll();

      $ebayListingIds = [];
      $titles = [];
      $listingStatuses = [];

      foreach ($listingRows as $listingRow) {
        $ebayListingIds[] = (string) ($listingRow->ebay_listing_id ?? '');
        $titles[] = $this->normalizeNullableString($listingRow->title ?? NULL) ?? 'Untitled legacy listing';
        $listingStatuses[] = $this->normalizeNullableString($listingRow->listing_status ?? NULL) ?? 'unknown';
      }

      $rows[] = [
        'sku' => $sku,
        'legacy_listing_count' => (int) ($duplicateSkuResult->legacy_listing_count ?? 0),
        'ebay_listing_ids' => $ebayListingIds,
        'titles' => $titles,
        'listing_statuses' => $listingStatuses,
      ];
    }

    return $rows;
  }

  /**
   * Find legacy listings that have no usable SKU.
   *
   * These rows are blocked for migration because Sell inventory needs a SKU.
   *
   * @return array<int,array{
   *   ebay_listing_id:string,
   *   sku:?string,
   *   title:?string,
   *   ebay_listing_started_at:?int,
   *   listing_status:?string
   * }>
   */
  public function findLegacyListingsMissingSku(int $accountId): array {
    $query = $this->database->select('bb_ebay_legacy_listing', 'legacy');
    $query->fields('legacy', [
      'ebay_listing_id',
      'sku',
      'title',
      'ebay_listing_started_at',
      'listing_status',
    ]);
    $query->condition('legacy.account_id', $accountId);
    $query->where('(legacy.sku IS NULL OR TRIM(legacy.sku) = \'\')');
    $query->orderBy('legacy.ebay_listing_id', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $rows[] = [
        'ebay_listing_id' => (string) ($result->ebay_listing_id ?? ''),
        'sku' => $this->normalizeNullableString($result->sku ?? NULL),
        'title' => $this->normalizeNullableString($result->title ?? NULL),
        'ebay_listing_started_at' => $this->normalizeNullableInt($result->ebay_listing_started_at ?? NULL),
        'listing_status' => $this->normalizeNullableString($result->listing_status ?? NULL),
      ];
    }

    return $rows;
  }

  /**
   * Find legacy listings that are clean candidates for Sell migration.
   *
   * Ready means:
   * - visible in the legacy mirror
   * - not already visible in the Sell offer mirror
   * - has a usable SKU
   * - does not share that SKU with another legacy listing
   *
   * @return array<int,array{
   *   ebay_listing_id:string,
   *   sku:string,
   *   title:?string,
   *   ebay_listing_started_at:?int,
   *   listing_status:?string
   * }>
   */
  public function findLegacyListingsReadyToMigrate(int $accountId): array {
    $duplicateSkuQuery = $this->database->select('bb_ebay_legacy_listing', 'dup');
    $duplicateSkuQuery->addExpression('dup.sku', 'sku');
    $duplicateSkuQuery->condition('dup.account_id', $accountId);
    $duplicateSkuQuery->isNotNull('dup.sku');
    $duplicateSkuQuery->where("TRIM(dup.sku) <> ''");
    $duplicateSkuQuery->groupBy('dup.sku');
    $duplicateSkuQuery->having('COUNT(*) > 1');

    $query = $this->database->select('bb_ebay_legacy_listing', 'legacy');
    $query->leftJoin(
      'bb_ebay_offer',
      'offer',
      'offer.account_id = legacy.account_id AND offer.listing_id = legacy.ebay_listing_id'
    );
    $query->leftJoin(
      $duplicateSkuQuery,
      'duplicate_sku',
      'duplicate_sku.sku = legacy.sku'
    );

    $query->fields('legacy', [
      'ebay_listing_id',
      'sku',
      'title',
      'ebay_listing_started_at',
      'listing_status',
    ]);
    $query->condition('legacy.account_id', $accountId);
    $query->isNull('offer.offer_id');
    $query->isNotNull('legacy.sku');
    $query->where("TRIM(legacy.sku) <> ''");
    $query->isNull('duplicate_sku.sku');
    $query->orderBy('legacy.ebay_listing_id', 'ASC');

    $results = $query->execute()->fetchAll();
    $rows = [];

    foreach ($results as $result) {
      $rows[] = [
        'ebay_listing_id' => (string) ($result->ebay_listing_id ?? ''),
        'sku' => (string) ($result->sku ?? ''),
        'title' => $this->normalizeNullableString($result->title ?? NULL),
        'ebay_listing_started_at' => $this->normalizeNullableInt($result->ebay_listing_started_at ?? NULL),
        'listing_status' => $this->normalizeNullableString($result->listing_status ?? NULL),
      ];
    }

    return $rows;
  }

  private function extractSkuIdentifier(string $sku): ?string {
    $marker = 'ai-book-';
    $position = strrpos($sku, $marker);
    if ($position === FALSE) {
      return NULL;
    }

    $identifier = trim(substr($sku, $position + strlen($marker)));
    return $identifier === '' ? NULL : $identifier;
  }

  /**
   * @return array{id:int,listing_code:?string,ebay_title:?string}|null
   */
  private function resolveListingFromSkuIdentifier(string $skuIdentifier): ?array {
    $byCode = $this->database->select('bb_ai_listing', 'listing')
      ->fields('listing', ['id', 'listing_code', 'ebay_title'])
      ->condition('listing_code', $skuIdentifier)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (is_array($byCode)) {
      return [
        'id' => (int) $byCode['id'],
        'listing_code' => $this->normalizeNullableString($byCode['listing_code'] ?? NULL),
        'ebay_title' => $this->normalizeNullableString($byCode['ebay_title'] ?? NULL),
      ];
    }

    if (!ctype_digit($skuIdentifier)) {
      return NULL;
    }

    $byId = $this->database->select('bb_ai_listing', 'listing')
      ->fields('listing', ['id', 'listing_code', 'ebay_title'])
      ->condition('id', (int) $skuIdentifier)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!is_array($byId)) {
      return NULL;
    }

    return [
      'id' => (int) $byId['id'],
      'listing_code' => $this->normalizeNullableString($byId['listing_code'] ?? NULL),
      'ebay_title' => $this->normalizeNullableString($byId['ebay_title'] ?? NULL),
    ];
  }

  /**
   * @return array{listing_id:int,marketplace_listing_id:?string}|null
   */
  private function loadPublishedPublicationBySku(string $sku): ?array {
    $publication = $this->database->select('ai_marketplace_publication', 'publication')
      ->fields('publication', ['listing', 'marketplace_listing_id'])
      ->condition('marketplace_key', 'ebay')
      ->condition('status', 'published')
      ->condition('inventory_sku_value', $sku)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!is_array($publication)) {
      return NULL;
    }

    return [
      'listing_id' => (int) $publication['listing'],
      'marketplace_listing_id' => $this->normalizeNullableString($publication['marketplace_listing_id'] ?? NULL),
    ];
  }

  private function normalizeNullableString(mixed $value): ?string {
    if (!is_scalar($value)) {
      return NULL;
    }

    $normalizedValue = trim((string) $value);
    return $normalizedValue === '' ? NULL : $normalizedValue;
  }

  private function normalizeNullableInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return (int) $value;
  }

}
