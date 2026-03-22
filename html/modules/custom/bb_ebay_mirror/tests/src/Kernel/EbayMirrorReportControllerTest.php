<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_mirror\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\bb_ebay_mirror\Controller\EbayMirrorReportController;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests that the eBay mirror report page can build its render array safely.
 *
 * Why this exists:
 * the audit services can be correct while the report page still breaks. This
 * test exercises the controller itself so link cells, section wiring, and the
 * new SKU mismatch table all get a basic safety check.
 */
final class EbayMirrorReportControllerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
    'ebay_infrastructure',
    'ebay_connector',
    'bb_ebay_mirror',
    'bb_ebay_legacy_migration',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installEntitySchema('ebay_account');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);
    $this->installSchema('bb_ebay_legacy_migration', ['bb_ebay_legacy_listing']);
    $this->installConfig(['field', 'ai_listing']);

    $this->createBookType();
    $this->createBookField('field_title');
    $this->createProductionAccount();
  }

  public function testReportBuildsAllCurrentAuditSections(): void {
    $listing = $this->createBookListing(
      ebayTitle: 'Mirror report listing',
      fieldTitle: 'Mirror report listing',
      storageLocation: 'BDMAA10'
    );
    $listing->set('listing_code', 'MIRROR01');
    $listing->save();

    $legacyLinkedListing = $this->createBookListing(
      ebayTitle: 'Legacy linked listing',
      fieldTitle: 'Legacy linked listing',
      storageLocation: 'BDMAA11'
    );
    $legacyLinkedListing->set('listing_code', 'LEGACY01');
    $legacyLinkedListing->save();

    $this->createPublication((int) $listing->id(), 'sku-missing', 'listing-11');
    $this->createPublication((int) $legacyLinkedListing->id(), '2024 September A01', 'listing-22');
    $this->seedMirroredInventorySku(1, '2026 Mar BDMCC05 ai-book-MIRROR01');
    $this->seedMirroredOffer(1, 'offer-mirror', '2026 Mar BDMCC05 ai-book-MIRROR01');
    $this->seedMirroredInventorySku(1, '2024 September A01');
    $this->seedLegacyListing(1, '176577811710', '2024 September A01', 'Legacy unmigrated listing', 1726406100, 'Active');
    $this->seedLegacyListing(1, '176582430935', '2024 September A01', 'Legacy migrated listing', 1726406100, 'Active');
    $this->seedMirroredOffer(1, 'offer-legacy-migrated', '2024 September A01', '176582430935', 'ACTIVE', 'PUBLISHED');

    $controller = EbayMirrorReportController::create($this->container);
    $build = $controller->build();

    $this->assertArrayHasKey('summary', $build);
    $this->assertArrayHasKey('missing_inventory', $build);
    $this->assertArrayHasKey('missing_offers', $build);
    $this->assertArrayHasKey('orphaned_inventory', $build);
    $this->assertArrayHasKey('orphaned_offers', $build);
    $this->assertArrayHasKey('sku_identifier_missing', $build);
    $this->assertArrayHasKey('sku_link_mismatch', $build);
    $this->assertArrayHasKey('multiple_inventory', $build);
    $this->assertArrayHasKey('multiple_offers', $build);
    $this->assertArrayHasKey('legacy_unmigrated', $build);
    $this->assertArrayHasKey('legacy_migrated', $build);
    $this->assertArrayHasKey('legacy_duplicate_sku', $build);
    $this->assertArrayHasKey('legacy_missing_sku', $build);
    $this->assertArrayHasKey('legacy_ready_to_migrate', $build);

    $this->assertSame('table', $build['missing_inventory']['table']['#type']);
    $this->assertSame('table', $build['sku_identifier_missing']['table']['#type']);
    $this->assertSame('table', $build['sku_link_mismatch']['table']['#type']);
    $this->assertSame('table', $build['multiple_inventory']['table']['#type']);
    $this->assertSame('table', $build['multiple_offers']['table']['#type']);
    $this->assertSame('table', $build['legacy_unmigrated']['table']['#type']);
    $this->assertSame('table', $build['legacy_migrated']['table']['#type']);
    $this->assertSame('table', $build['legacy_duplicate_sku']['table']['#type']);
    $this->assertSame('table', $build['legacy_missing_sku']['table']['#type']);
    $this->assertSame('table', $build['legacy_ready_to_migrate']['table']['#type']);

    $missingInventoryRows = $build['missing_inventory']['table']['#rows'];
    $this->assertCount(1, $missingInventoryRows);
    $this->assertStringContainsString((string) $listing->id(), (string) $missingInventoryRows[0][0]);
    $this->assertSame('MIRROR01', (string) $missingInventoryRows[0][1]);

    $skuIdentifierMissingRows = $build['sku_identifier_missing']['table']['#rows'];
    $this->assertCount(1, $skuIdentifierMissingRows);
    $this->assertSame('2024 September A01', (string) $skuIdentifierMissingRows[0][0]);
    $this->assertStringContainsString((string) $legacyLinkedListing->id(), (string) $skuIdentifierMissingRows[0][1]);
    $this->assertSame('LEGACY01', strip_tags((string) $skuIdentifierMissingRows[0][2]));

    $skuMismatchRows = $build['sku_link_mismatch']['table']['#rows'];
    $this->assertCount(1, $skuMismatchRows);
    $this->assertSame('2026 Mar BDMCC05 ai-book-MIRROR01', (string) $skuMismatchRows[0][0]);
    $this->assertSame('MIRROR01', (string) $skuMismatchRows[0][1]);
    $this->assertStringContainsString((string) $listing->id(), (string) $skuMismatchRows[0][2]);
    $this->assertSame('MIRROR01', strip_tags((string) $skuMismatchRows[0][3]));

    $this->assertSame('No rows in this bucket.', (string) $build['multiple_inventory']['table']['#empty']);
    $this->assertSame('No rows in this bucket.', (string) $build['multiple_offers']['table']['#empty']);

    $legacyUnmigratedRows = $build['legacy_unmigrated']['table']['#rows'];
    $this->assertCount(1, $legacyUnmigratedRows);
    $this->assertSame('176577811710', (string) $legacyUnmigratedRows[0][0]);
    $this->assertSame('2024 September A01', (string) $legacyUnmigratedRows[0][1]);

    $legacyMigratedRows = $build['legacy_migrated']['table']['#rows'];
    $this->assertCount(1, $legacyMigratedRows);
    $this->assertSame('176582430935', (string) $legacyMigratedRows[0][0]);
    $this->assertSame('offer-legacy-migrated', (string) $legacyMigratedRows[0][5]);

    $legacyDuplicateSkuRows = $build['legacy_duplicate_sku']['table']['#rows'];
    $this->assertCount(1, $legacyDuplicateSkuRows);
    $this->assertSame('2024 September A01', (string) $legacyDuplicateSkuRows[0][0]);
    $this->assertSame('2', (string) $legacyDuplicateSkuRows[0][1]);
    $this->assertSame('176577811710, 176582430935', (string) $legacyDuplicateSkuRows[0][2]);

    $this->assertSame('No rows in this bucket.', (string) $build['legacy_missing_sku']['table']['#empty']);
    $this->assertSame('No rows in this bucket.', (string) $build['legacy_ready_to_migrate']['table']['#empty']);
  }

  private function createProductionAccount(): void {
    $user = User::create([
      'name' => 'mirror-admin',
    ]);
    $user->save();

    $account = EbayAccount::create([
      'label' => 'Primary eBay Account',
      'uid' => (int) $user->id(),
      'environment' => 'production',
      'access_token' => 'token',
      'refresh_token' => 'refresh',
      'expires_at' => time() + 3600,
    ]);
    $account->save();
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

  private function seedMirroredInventorySku(int $accountId, string $sku): void {
    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => $accountId,
        'sku' => $sku,
        'title' => 'Seeded mirror title',
        'available_quantity' => 1,
        'condition' => 'USED_GOOD',
        'last_seen' => time(),
      ])
      ->execute();
  }

  private function seedMirroredOffer(
    int $accountId,
    string $offerId,
    string $sku,
    ?string $listingId = NULL,
    string $listingStatus = 'UNPUBLISHED',
    string $status = 'UNPUBLISHED',
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

    if (FieldConfig::loadByName('bb_ai_listing', 'book', $fieldName)) {
      return;
    }

    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'bb_ai_listing',
      'bundle' => 'book',
      'label' => ucfirst(str_replace('_', ' ', $fieldName)),
    ])->save();
  }

}
