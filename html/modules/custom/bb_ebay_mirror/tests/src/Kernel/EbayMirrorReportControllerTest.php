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
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'ebay_infrastructure',
    'ebay_connector',
    'bb_ebay_mirror',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installEntitySchema('ebay_account');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);
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

    $this->createPublication((int) $listing->id(), 'sku-missing', 'listing-11');
    $this->seedMirroredInventorySku(1, '2026 Mar BDMCC05 ai-book-MIRROR01');
    $this->seedMirroredOffer(1, 'offer-mirror', '2026 Mar BDMCC05 ai-book-MIRROR01');

    $controller = EbayMirrorReportController::create($this->container);
    $build = $controller->build();

    $this->assertArrayHasKey('summary', $build);
    $this->assertArrayHasKey('missing_inventory', $build);
    $this->assertArrayHasKey('missing_offers', $build);
    $this->assertArrayHasKey('orphaned_inventory', $build);
    $this->assertArrayHasKey('orphaned_offers', $build);
    $this->assertArrayHasKey('sku_link_mismatch', $build);

    $this->assertSame('table', $build['missing_inventory']['table']['#type']);
    $this->assertSame('table', $build['sku_link_mismatch']['table']['#type']);

    $missingInventoryRows = $build['missing_inventory']['table']['#rows'];
    $this->assertCount(1, $missingInventoryRows);
    $this->assertStringContainsString((string) $listing->id(), (string) $missingInventoryRows[0][0]);
    $this->assertSame('MIRROR01', (string) $missingInventoryRows[0][1]);

    $skuMismatchRows = $build['sku_link_mismatch']['table']['#rows'];
    $this->assertCount(1, $skuMismatchRows);
    $this->assertSame('2026 Mar BDMCC05 ai-book-MIRROR01', (string) $skuMismatchRows[0][0]);
    $this->assertSame('MIRROR01', (string) $skuMismatchRows[0][1]);
    $this->assertStringContainsString((string) $listing->id(), (string) $skuMismatchRows[0][2]);
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

  private function seedMirroredOffer(int $accountId, string $offerId, string $sku): void {
    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => $accountId,
        'offer_id' => $offerId,
        'sku' => $sku,
        'listing_status' => 'UNPUBLISHED',
        'status' => 'UNPUBLISHED',
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
