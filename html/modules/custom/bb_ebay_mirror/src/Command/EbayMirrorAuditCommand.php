<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Command;

use Drupal\bb_ebay_mirror\Service\EbayMirrorAuditService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drush\Commands\DrushCommands;

final class EbayMirrorAuditCommand extends DrushCommands {

  public function __construct(
    private readonly EbayMirrorAuditService $auditService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Audit local published eBay listings that are missing mirrored inventory.
   *
   * @command bb-ebay-mirror:audit-missing-inventory
   */
  public function auditMissingInventory(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findPublishedListingsMissingMirroredInventory((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No local published eBay listings are missing mirrored inventory for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d local published eBay listings with no mirrored inventory for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->writeAuditRow($row);
    }
  }

  /**
   * Audit local published eBay listings that are missing mirrored offers.
   *
   * @command bb-ebay-mirror:audit-missing-offers
   */
  public function auditMissingOffers(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findPublishedListingsMissingMirroredOffer((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No local published eBay listings are missing mirrored offers for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d local published eBay listings with no mirrored offer for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->writeAuditRow($row);
    }
  }

  /**
   * Audit mirrored inventory rows that have no local published listing.
   *
   * @command bb-ebay-mirror:audit-orphaned-inventory
   */
  public function auditOrphanedInventory(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findMirroredInventoryMissingLocalListing((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No mirrored inventory rows are missing a local published listing for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d mirrored inventory rows with no local published listing for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- sku %s | quantity %s | condition %s | %s',
        $row['sku'],
        $row['available_quantity'] === NULL ? 'unknown' : (string) $row['available_quantity'],
        $row['condition'] ?? 'unknown',
        $row['title'] ?? 'Untitled inventory item'
      ));
    }
  }

  /**
   * Audit mirrored offers that have no local published listing.
   *
   * @command bb-ebay-mirror:audit-orphaned-offers
   */
  public function auditOrphanedOffers(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findMirroredOffersMissingLocalListing((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No mirrored offers are missing a local published listing for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d mirrored offers with no local published listing for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- offer %s | sku %s | listingId %s | listingStatus %s | offerStatus %s',
        $row['offer_id'],
        $row['sku'],
        $row['listing_id'] ?? 'unknown',
        $row['listing_status'] ?? 'unknown',
        $row['status'] ?? 'unknown'
      ));
    }
  }

  /**
   * Audit mirrored SKUs whose embedded listing identifier disagrees with Drupal.
   *
   * @command bb-ebay-mirror:audit-sku-link-mismatch
   */
  public function auditSkuLinkMismatch(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findSkuLinkMismatches((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No mirrored SKUs disagree with local listing/publication links for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d mirrored SKUs whose embedded identifier disagrees with local linkage for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- sku %s | identifier %s | resolved listing %s | publication listing %s | offer %s | offerStatus %s | %s',
        $row['sku'],
        $row['sku_identifier'] ?? 'unknown',
        $row['resolved_listing_id'] === NULL
          ? 'unknown'
          : (string) $row['resolved_listing_id'] . ($row['resolved_listing_code'] ? ' (' . $row['resolved_listing_code'] . ')' : ''),
        $row['publication_listing_id'] === NULL ? 'none' : (string) $row['publication_listing_id'],
        $row['offer_id'] ?? 'none',
        $row['offer_status'] ?? 'unknown',
        str_replace('_', ' ', $row['reason'])
      ));
    }
  }

  /**
   * Audit local listings that resolve from multiple mirrored inventory SKUs.
   *
   * @command bb-ebay-mirror:audit-multiple-inventory
   */
  public function auditMultipleInventory(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findListingsWithMultipleMirroredInventorySkus((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No local listings resolve from multiple mirrored inventory SKUs for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d local listings that resolve from multiple mirrored inventory SKUs for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- listing %d | listingCode %s | skuCount %d | %s | skus: %s',
        $row['listing_id'],
        $row['listing_code'] ?? 'unset',
        $row['mirrored_sku_count'],
        $row['ebay_title'] ?? 'Untitled listing',
        implode(', ', $row['mirrored_skus'])
      ));
    }
  }

  /**
   * Audit local listings that resolve from multiple mirrored offers.
   *
   * @command bb-ebay-mirror:audit-multiple-offers
   */
  public function auditMultipleOffers(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findListingsWithMultipleMirroredOffers((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No local listings resolve from multiple mirrored offers for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d local listings that resolve from multiple mirrored offers for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- listing %d | listingCode %s | offerCount %d | %s | offers: %s | skus: %s',
        $row['listing_id'],
        $row['listing_code'] ?? 'unset',
        $row['mirrored_offer_count'],
        $row['ebay_title'] ?? 'Untitled listing',
        implode(', ', $row['mirrored_offers']),
        implode(', ', $row['mirrored_skus'])
      ));
    }
  }

  /**
   * Audit legacy listings that are not yet visible in the Sell offer mirror.
   *
   * @command bb-ebay-mirror:audit-legacy-unmigrated
   */
  public function auditLegacyUnmigrated(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findLegacyListingsMissingMirroredSellOffer((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No legacy listings are missing mirrored Sell offers for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d legacy listings with no mirrored Sell offer for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- listingId %s | sku %s | started %s | status %s | %s',
        $row['ebay_listing_id'],
        $row['sku'] ?? 'unset',
        $row['ebay_listing_started_at'] === NULL ? 'unknown' : gmdate('Y-m-d H:i:s', $row['ebay_listing_started_at']),
        $row['listing_status'] ?? 'unknown',
        $row['title'] ?? 'Untitled legacy listing'
      ));
    }
  }

  /**
   * Audit legacy listings that are already visible in the Sell offer mirror.
   *
   * @command bb-ebay-mirror:audit-legacy-migrated
   */
  public function auditLegacyMigrated(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findLegacyListingsWithMirroredSellOffer((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No legacy listings are already visible in the Sell offer mirror for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d legacy listings that already have mirrored Sell offers for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- listingId %s | sku %s | offer %s | offerStatus %s | %s',
        $row['ebay_listing_id'],
        $row['sku'] ?? 'unset',
        $row['mirrored_offer_id'],
        $row['mirrored_offer_status'] ?? 'unknown',
        $row['title'] ?? 'Untitled legacy listing'
      ));
    }
  }

  /**
   * Audit legacy listings that share duplicate SKUs.
   *
   * @command bb-ebay-mirror:audit-legacy-duplicate-sku
   */
  public function auditLegacyDuplicateSku(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findLegacyListingsWithDuplicateSku((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No legacy listings share duplicate SKUs for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d duplicate legacy SKU groups for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- sku %s | listingCount %d | listingIds %s | statuses %s | titles %s',
        $row['sku'],
        $row['legacy_listing_count'],
        implode(', ', $row['ebay_listing_ids']),
        implode(', ', $row['listing_statuses']),
        implode(' || ', $row['titles'])
      ));
    }
  }

  /**
   * Audit legacy listings that have no usable SKU.
   *
   * @command bb-ebay-mirror:audit-legacy-missing-sku
   */
  public function auditLegacyMissingSku(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findLegacyListingsMissingSku((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No legacy listings are missing a usable SKU for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d legacy listings with no usable SKU for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- listingId %s | started %s | status %s | %s',
        $row['ebay_listing_id'],
        $row['ebay_listing_started_at'] === NULL ? 'unknown' : gmdate('Y-m-d H:i:s', $row['ebay_listing_started_at']),
        $row['listing_status'] ?? 'unknown',
        $row['title'] ?? 'Untitled legacy listing'
      ));
    }
  }

  /**
   * Audit legacy listings that are clean migration candidates.
   *
   * @command bb-ebay-mirror:audit-legacy-ready-to-migrate
   */
  public function auditLegacyReadyToMigrate(?int $accountId = NULL): void {
    $account = $this->resolveAccount($accountId);
    $rows = $this->auditService->findLegacyListingsReadyToMigrate((int) $account->id());

    if ($rows === []) {
      $this->output()->writeln(sprintf(
        'No legacy listings are currently ready to migrate for account %d (%s).',
        (int) $account->id(),
        (string) $account->label()
      ));
      return;
    }

    $this->output()->writeln(sprintf(
      'Found %d legacy listings ready to migrate for account %d (%s):',
      count($rows),
      (int) $account->id(),
      (string) $account->label()
    ));

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '- listingId %s | sku %s | started %s | status %s | %s',
        $row['ebay_listing_id'],
        $row['sku'],
        $row['ebay_listing_started_at'] === NULL ? 'unknown' : gmdate('Y-m-d H:i:s', $row['ebay_listing_started_at']),
        $row['listing_status'] ?? 'unknown',
        $row['title'] ?? 'Untitled legacy listing'
      ));
    }
  }

  private function resolveAccount(?int $accountId): EbayAccount {
    $storage = $this->entityTypeManager->getStorage('ebay_account');

    if ($accountId !== NULL) {
      $account = $storage->load($accountId);
      if (!$account instanceof EbayAccount) {
        throw new \RuntimeException(sprintf('eBay account %d was not found.', $accountId));
      }

      return $account;
    }

    $accounts = $storage->loadByProperties(['environment' => 'production']);
    $account = reset($accounts);
    if (!$account instanceof EbayAccount) {
      throw new \RuntimeException('No production eBay account found.');
    }

    return $account;
  }

  /**
   * @param array{listing_id:int,ebay_title:?string,storage_location:?string,sku:string,marketplace_listing_id:?string} $row
   */
  private function writeAuditRow(array $row): void {
    $this->output()->writeln(sprintf(
      '- listing %d | sku %s | location %s | listingId %s | %s',
      $row['listing_id'],
      $row['sku'],
      $row['storage_location'] ?? 'unset',
      $row['marketplace_listing_id'] ?? 'unknown',
      $row['ebay_title'] ?? 'Untitled listing'
    ));
  }

}
