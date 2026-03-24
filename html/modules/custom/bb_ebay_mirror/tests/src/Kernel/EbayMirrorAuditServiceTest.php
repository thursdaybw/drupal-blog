<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_mirror\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\bb_ebay_mirror\Service\EbayMirrorAuditService;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_listing\Traits\InstallsBbAiListingKernelSchemaTrait;

/**
 * Tests the first eBay mirror audit report.
 *
 * What this is testing:
 * the audit service should find local listings that Drupal still thinks are
 * published to eBay, but whose SKU is missing from the mirrored inventory
 * table.
 *
 * Why this matters:
 * this is one of the first useful checks the mirror can give us. It tells us
 * when local publish state and mirrored eBay state have drifted apart.
 */
final class EbayMirrorAuditServiceTest extends KernelTestBase {

  use InstallsBbAiListingKernelSchemaTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'options',
    'taxonomy',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
    'ebay_infrastructure',
    'ebay_connector',
    'bb_ebay_mirror',
    'bb_ebay_legacy_migration',
  ];

  private EbayMirrorAuditService $auditService;

  protected function setUp(): void {
    parent::setUp();

    $this->installBbAiListingKernelSchema();
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);
    $this->installSchema('bb_ebay_legacy_migration', ['bb_ebay_legacy_listing', 'bb_ebay_legacy_listing_link']);
    $this->installConfig(['field', 'ai_listing']);

    $this->createBookType();
    $this->createBookBundleType();
    $this->createBookField('field_title');

    $this->auditService = $this->container->get('bb_ebay_mirror.audit_service');
  }

  public function testFindPublishedListingsMissingMirroredInventory(): void {
    $missingListing = $this->createBookListing(
      ebayTitle: 'Missing on mirror',
      fieldTitle: 'Missing on mirror',
      storageLocation: 'BDMAA01'
    );
    $presentListing = $this->createBookListing(
      ebayTitle: 'Present on mirror',
      fieldTitle: 'Present on mirror',
      storageLocation: 'BDMAA02'
    );

    $this->createPublication((int) $missingListing->id(), 'sku-missing', 'listing-1');
    $this->createPublication((int) $presentListing->id(), 'sku-present', 'listing-2');

    $this->seedMirroredInventorySku(1, 'sku-present');

    $rows = $this->auditService->findPublishedListingsMissingMirroredInventory(1);

    $this->assertCount(1, $rows);
    $this->assertSame((int) $missingListing->id(), $rows[0]['listing_id']);
    $this->assertSame('Missing on mirror', $rows[0]['ebay_title']);
    $this->assertSame('BDMAA01', $rows[0]['storage_location']);
    $this->assertSame('sku-missing', $rows[0]['sku']);
    $this->assertSame('listing-1', $rows[0]['marketplace_listing_id']);
  }

  public function testFindPublishedListingsMissingMirroredOffer(): void {
    $missingListing = $this->createBookListing(
      ebayTitle: 'Missing offer on mirror',
      fieldTitle: 'Missing offer on mirror',
      storageLocation: 'BDMAA03'
    );
    $presentListing = $this->createBookListing(
      ebayTitle: 'Present offer on mirror',
      fieldTitle: 'Present offer on mirror',
      storageLocation: 'BDMAA04'
    );

    $this->createPublication((int) $missingListing->id(), 'offer-missing-sku', 'listing-3');
    $this->createPublication((int) $presentListing->id(), 'offer-present-sku', 'listing-4');

    $this->seedMirroredOffer(1, 'offer-1', 'offer-present-sku');

    $rows = $this->auditService->findPublishedListingsMissingMirroredOffer(1);

    $this->assertCount(1, $rows);
    $this->assertSame((int) $missingListing->id(), $rows[0]['listing_id']);
    $this->assertSame('Missing offer on mirror', $rows[0]['ebay_title']);
    $this->assertSame('BDMAA03', $rows[0]['storage_location']);
    $this->assertSame('offer-missing-sku', $rows[0]['sku']);
    $this->assertSame('listing-3', $rows[0]['marketplace_listing_id']);
  }

  public function testFindMirroredInventoryMissingLocalListing(): void {
    $this->seedMirroredInventorySku(1, 'orphan-sku', 'Orphan inventory', 2, 'USED_GOOD');
    $this->seedMirroredInventorySku(1, 'linked-sku', 'Linked inventory', 1, 'USED_GOOD');

    $linkedListing = $this->createBookListing(
      ebayTitle: 'Linked listing',
      fieldTitle: 'Linked listing',
      storageLocation: 'BDMAA05'
    );
    $this->createPublication((int) $linkedListing->id(), 'linked-sku', 'listing-5');

    $rows = $this->auditService->findMirroredInventoryMissingLocalListing(1);

    $this->assertCount(1, $rows);
    $this->assertSame('orphan-sku', $rows[0]['sku']);
    $this->assertSame('Orphan inventory', $rows[0]['title']);
    $this->assertSame(2, $rows[0]['available_quantity']);
    $this->assertSame('USED_GOOD', $rows[0]['condition']);
  }

  public function testFindMirroredOffersMissingLocalListing(): void {
    $this->seedMirroredOffer(1, 'offer-orphan', 'orphan-offer-sku', 'listing-9', 'ACTIVE', 'PUBLISHED');
    $this->seedMirroredOffer(1, 'offer-linked', 'linked-offer-sku', 'listing-10', 'ACTIVE', 'PUBLISHED');

    $linkedListing = $this->createBookListing(
      ebayTitle: 'Linked offer listing',
      fieldTitle: 'Linked offer listing',
      storageLocation: 'BDMAA06'
    );
    $this->createPublication((int) $linkedListing->id(), 'linked-offer-sku', 'listing-10');

    $rows = $this->auditService->findMirroredOffersMissingLocalListing(1);

    $this->assertCount(1, $rows);
    $this->assertSame('offer-orphan', $rows[0]['offer_id']);
    $this->assertSame('orphan-offer-sku', $rows[0]['sku']);
    $this->assertSame('listing-9', $rows[0]['listing_id']);
    $this->assertSame('ACTIVE', $rows[0]['listing_status']);
    $this->assertSame('PUBLISHED', $rows[0]['status']);
  }

  public function testFindSkuLinkMismatchesPrefersListingCodeAndFallsBackToEntityId(): void {
    $codeListing = $this->createBookListing(
      ebayTitle: 'Code listing',
      fieldTitle: 'Code listing',
      storageLocation: 'BDMAA07'
    );
    $codeListing->set('listing_code', 'BOOKABCD');
    $codeListing->save();

    $legacyListing = $this->createBookListing(
      ebayTitle: 'Legacy listing',
      fieldTitle: 'Legacy listing',
      storageLocation: 'BDMAA08'
    );

    $wrongPublicationListing = $this->createBookListing(
      ebayTitle: 'Wrong publication owner',
      fieldTitle: 'Wrong publication owner',
      storageLocation: 'BDMAA09'
    );

    $this->seedMirroredInventorySku(1, '2026 Mar BDMCC05 ai-book-BOOKABCD');
    $this->seedMirroredOffer(1, 'offer-code', '2026 Mar BDMCC05 ai-book-BOOKABCD');

    $legacySku = '2026 Mar BDMCC05 ai-book-' . $legacyListing->id();
    $this->seedMirroredInventorySku(1, $legacySku);
    $this->seedMirroredOffer(1, 'offer-legacy', $legacySku);
    $this->createPublication((int) $wrongPublicationListing->id(), $legacySku, 'listing-99');

    $rows = $this->auditService->findSkuLinkMismatches(1);

    $this->assertCount(2, $rows);

    $rowsBySku = [];
    foreach ($rows as $row) {
      $rowsBySku[$row['sku']] = $row;
    }

    $this->assertSame('BOOKABCD', $rowsBySku['2026 Mar BDMCC05 ai-book-BOOKABCD']['sku_identifier']);
    $this->assertSame((int) $codeListing->id(), $rowsBySku['2026 Mar BDMCC05 ai-book-BOOKABCD']['resolved_listing_id']);
    $this->assertSame('BOOKABCD', $rowsBySku['2026 Mar BDMCC05 ai-book-BOOKABCD']['resolved_listing_code']);
    $this->assertSame('missing_local_publication_link', $rowsBySku['2026 Mar BDMCC05 ai-book-BOOKABCD']['reason']);

    $this->assertSame((string) $legacyListing->id(), $rowsBySku[$legacySku]['sku_identifier']);
    $this->assertSame((int) $legacyListing->id(), $rowsBySku[$legacySku]['resolved_listing_id']);
    $this->assertSame((int) $wrongPublicationListing->id(), $rowsBySku[$legacySku]['publication_listing_id']);
    $this->assertSame('publication_points_to_different_listing', $rowsBySku[$legacySku]['reason']);
  }

  public function testFindListingsWithMultipleMirroredInventorySkus(): void {
    $listing = $this->createBookListing(
      ebayTitle: 'Multiple inventory listing',
      fieldTitle: 'Multiple inventory listing',
      storageLocation: 'BDMAA11'
    );
    $listing->set('listing_code', 'MULTIINV');
    $listing->save();

    $this->seedMirroredInventorySku(1, '2026 Mar BDMCC05 ai-book-MULTIINV');
    $this->seedMirroredInventorySku(1, '2026 Mar BRNCBD004 ai-book-MULTIINV');

    $rows = $this->auditService->findListingsWithMultipleMirroredInventorySkus(1);

    $this->assertCount(1, $rows);
    $this->assertSame((int) $listing->id(), $rows[0]['listing_id']);
    $this->assertSame('MULTIINV', $rows[0]['listing_code']);
    $this->assertSame(2, $rows[0]['mirrored_sku_count']);
    $this->assertSame([
      '2026 Mar BDMCC05 ai-book-MULTIINV',
      '2026 Mar BRNCBD004 ai-book-MULTIINV',
    ], $rows[0]['mirrored_skus']);
  }

  public function testFindListingsWithMultipleMirroredOffers(): void {
    $listing = $this->createBookListing(
      ebayTitle: 'Multiple offer listing',
      fieldTitle: 'Multiple offer listing',
      storageLocation: 'BDMAA12'
    );
    $listing->set('listing_code', 'MULTIOFR');
    $listing->save();

    $this->seedMirroredOffer(1, 'offer-a', '2026 Mar BDMCC05 ai-book-MULTIOFR');
    $this->seedMirroredOffer(1, 'offer-b', '2026 Mar BRNCBD004 ai-book-MULTIOFR');

    $rows = $this->auditService->findListingsWithMultipleMirroredOffers(1);

    $this->assertCount(1, $rows);
    $this->assertSame((int) $listing->id(), $rows[0]['listing_id']);
    $this->assertSame('MULTIOFR', $rows[0]['listing_code']);
    $this->assertSame(2, $rows[0]['mirrored_offer_count']);
    $this->assertSame(['offer-a', 'offer-b'], $rows[0]['mirrored_offers']);
    $this->assertSame([
      '2026 Mar BDMCC05 ai-book-MULTIOFR',
      '2026 Mar BRNCBD004 ai-book-MULTIOFR',
    ], $rows[0]['mirrored_skus']);
  }

  public function testFindLegacyListingsMissingAndHavingMirroredSellOffers(): void {
    $linkedListing = $this->createBookListing(
      ebayTitle: 'Linked legacy listing',
      fieldTitle: 'Linked legacy listing',
      storageLocation: 'BDMAA13'
    );
    $linkedListing->set('listing_code', 'LEGACYLINK');
    $linkedListing->save();

    $this->seedLegacyListing(1, '176577811710', '2024 September A01', 'Legacy unmigrated listing', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176582430935', '2024 September A01', 'Legacy migrated listing', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176582430936', '2024 September A02', 'Legacy linked listing', 1726406200, 'Active');
    $this->seedLegacyListingLink(1, '176582430936', (int) $linkedListing->id());
    $this->seedMirroredOffer(1, 'offer-legacy-migrated', '2024 September A01', '176582430935', 'ACTIVE', 'PUBLISHED');

    $missingRows = $this->auditService->findLegacyListingsMissingMirroredSellOffer(1);
    $migratedRows = $this->auditService->findLegacyListingsWithMirroredSellOffer(1);

    $this->assertCount(1, $missingRows);
    $this->assertSame('176577811710', $missingRows[0]['ebay_listing_id']);
    $this->assertSame('2024 September A01', $missingRows[0]['sku']);
    $this->assertSame('Legacy unmigrated listing', $missingRows[0]['title']);
    $this->assertSame(1726406100, $missingRows[0]['ebay_listing_started_at']);

    $this->assertCount(1, $migratedRows);
    $this->assertSame('176582430935', $migratedRows[0]['ebay_listing_id']);
    $this->assertSame('offer-legacy-migrated', $migratedRows[0]['mirrored_offer_id']);
    $this->assertSame('PUBLISHED', $migratedRows[0]['mirrored_offer_status']);
  }

  public function testFindLegacyListingsWithDuplicateSku(): void {
    $this->seedLegacyListing(1, '176577811710', '2024 September A01', 'Legacy unmigrated listing', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176582430935', '2024 September A01', 'Legacy migrated listing', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176779515895', 'DVDWB01', 'Unique legacy listing', 1754000000, 'Active');

    $rows = $this->auditService->findLegacyListingsWithDuplicateSku(1);

    $this->assertCount(1, $rows);
    $this->assertSame('2024 September A01', $rows[0]['sku']);
    $this->assertSame(2, $rows[0]['legacy_listing_count']);
    $this->assertSame(['176577811710', '176582430935'], $rows[0]['ebay_listing_ids']);
    $this->assertSame(['Active', 'Active'], $rows[0]['listing_statuses']);
    $this->assertSame([
      'Legacy unmigrated listing',
      'Legacy migrated listing',
    ], $rows[0]['titles']);
  }

  public function testFindLegacyListingsMissingSku(): void {
    $this->seedLegacyListing(1, '176577811710', '', 'Blank SKU legacy listing', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176582430935', '   ', 'Whitespace SKU legacy listing', 1726406200, 'Active');
    $this->seedLegacyListing(1, '176779515895', 'DVDWB01', 'Unique legacy listing', 1754000000, 'Active');

    $rows = $this->auditService->findLegacyListingsMissingSku(1);

    $this->assertCount(2, $rows);
    $this->assertSame('176577811710', $rows[0]['ebay_listing_id']);
    $this->assertNull($rows[0]['sku']);
    $this->assertSame('Blank SKU legacy listing', $rows[0]['title']);
    $this->assertSame('176582430935', $rows[1]['ebay_listing_id']);
    $this->assertNull($rows[1]['sku']);
    $this->assertSame('Whitespace SKU legacy listing', $rows[1]['title']);
  }

  public function testFindLegacyListingsReadyToMigrate(): void {
    $linkedListing = $this->createBookListing(
      ebayTitle: 'Already linked local listing',
      fieldTitle: 'Already linked local listing',
      storageLocation: 'BDMAA14'
    );
    $linkedListing->set('listing_code', 'LINKEDREADY');
    $linkedListing->save();

    $this->seedLegacyListing(1, '176577811710', '2024 September A01', 'Duplicate legacy listing one', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176582430935', '2024 September A01', 'Duplicate legacy listing two', 1726406200, 'Active');
    $this->seedLegacyListing(1, '176779515895', '', 'Blank SKU legacy listing', 1754000000, 'Active');
    $this->seedLegacyListing(1, '176700000001', 'READY-SKU-1', 'Ready legacy listing', 1755000000, 'Active');
    $this->seedLegacyListing(1, '176700000002', 'MIGRATED-SKU-1', 'Already migrated legacy listing', 1755000100, 'Active');
    $this->seedLegacyListing(1, '176700000003', 'LINKED-SKU-1', 'Linked legacy listing', 1755000200, 'Active');
    $this->seedLegacyListingLink(1, '176700000003', (int) $linkedListing->id());
    $this->seedMirroredOffer(1, 'offer-already-migrated', 'MIGRATED-SKU-1', '176700000002', 'ACTIVE', 'PUBLISHED');

    $rows = $this->auditService->findLegacyListingsReadyToMigrate(1);

    $this->assertCount(1, $rows);
    $this->assertSame('176700000001', $rows[0]['ebay_listing_id']);
    $this->assertSame('READY-SKU-1', $rows[0]['sku']);
    $this->assertSame('Ready legacy listing', $rows[0]['title']);
    $this->assertSame(1755000000, $rows[0]['ebay_listing_started_at']);
  }

  private function createBookListing(
    string $ebayTitle,
    string $fieldTitle,
    string $storageLocation,
  ): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => $ebayTitle,
      'storage_location' => $storageLocation,
      'condition_grade' => 'good',
      'condition_note' => 'Clean copy.',
    ]);
    $listing->set('field_title', $fieldTitle);
    $listing->save();

    return $listing;
  }

  private function createPublication(int $listingId, string $sku, string $listingIdOnEbay): void {
    $publication = $this->container->get('entity_type.manager')
      ->getStorage('ai_marketplace_publication')
      ->create([
        'listing' => $listingId,
        'inventory_sku_value' => $sku,
        'marketplace_key' => 'ebay',
        'status' => 'published',
        'publication_type' => 'FIXED_PRICE',
        'marketplace_publication_id' => 'pub-' . $listingId,
        'marketplace_listing_id' => $listingIdOnEbay,
      ]);
    $publication->save();
  }

  private function seedMirroredInventorySku(
    int $accountId,
    string $sku,
    string $title = 'Seeded title',
    int $availableQuantity = 1,
    string $condition = 'USED_GOOD',
  ): void {
    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => $accountId,
        'sku' => $sku,
        'title' => $title,
        'available_quantity' => $availableQuantity,
        'condition' => $condition,
        'last_seen' => time(),
      ])
      ->execute();
  }

  private function seedMirroredOffer(
    int $accountId,
    string $offerId,
    string $sku,
    string $listingId = 'listing-1',
    string $listingStatus = 'ACTIVE',
    string $status = 'PUBLISHED',
  ): void {
    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => $accountId,
        'offer_id' => $offerId,
        'sku' => $sku,
        'listing_id' => $listingId,
        'listing_status' => $listingStatus,
        'status' => $status,
        'last_seen' => time(),
      ])
      ->execute();
  }

  private function seedLegacyListing(
    int $accountId,
    string $ebayListingId,
    string $sku,
    string $title,
    int $startedAt,
    string $listingStatus,
  ): void {
    $this->container->get('database')->insert('bb_ebay_legacy_listing')
      ->fields([
        'account_id' => $accountId,
        'ebay_listing_id' => $ebayListingId,
        'sku' => $sku,
        'title' => $title,
        'ebay_listing_started_at' => $startedAt,
        'listing_status' => $listingStatus,
        'last_seen' => time(),
      ])
      ->execute();
  }

  private function seedLegacyListingLink(int $accountId, string $ebayListingId, int $listingId): void {
    $this->container->get('database')->insert('bb_ebay_legacy_listing_link')
      ->fields([
        'listing' => $listingId,
        'account_id' => $accountId,
        'origin_type' => 'legacy_ebay_migrated',
        'ebay_listing_id' => $ebayListingId,
        'created' => time(),
        'changed' => time(),
      ])
      ->execute();
  }

  private function createBookBundleType(): void {
    if (BbAiListingType::load('book_bundle') instanceof BbAiListingType) {
      return;
    }

    BbAiListingType::create([
      'id' => 'book_bundle',
      'label' => 'Book bundle',
    ])->save();
  }

  private function createBookType(): void {
    if (BbAiListingType::load('book') instanceof BbAiListingType) {
      return;
    }

    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
    ])->save();
  }

  private function createBookField(string $fieldName): void {
    if (!FieldStorageConfig::loadByName('bb_ai_listing', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'bb_ai_listing',
        'type' => 'string',
        'settings' => ['max_length' => 255],
      ])->save();
    }

    foreach (['book', 'book_bundle'] as $bundle) {
      if (FieldConfig::loadByName('bb_ai_listing', $bundle, $fieldName)) {
        continue;
      }

      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'bb_ai_listing',
        'bundle' => $bundle,
        'label' => ucfirst(str_replace('_', ' ', $fieldName)),
      ])->save();
    }
  }

}
