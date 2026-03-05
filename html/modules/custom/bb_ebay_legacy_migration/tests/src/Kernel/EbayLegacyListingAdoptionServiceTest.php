<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_legacy_migration\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests adopting one mirrored migrated eBay listing into bb_ai_listing.
 *
 * This is a kernel test because the adoption service writes to real Drupal
 * entity storage and also writes to plain module-owned tables.
 */
final class EbayLegacyListingAdoptionServiceTest extends KernelTestBase {

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
    'ebay_connector',
    'ebay_infrastructure',
    'bb_ebay_mirror',
    'bb_ebay_legacy_migration',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('ebay_account');
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_listing_inventory_sku');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);
    $this->installSchema('bb_ebay_legacy_migration', ['bb_ebay_legacy_listing', 'bb_ebay_legacy_listing_link']);
    $this->installConfig(['field', 'ai_listing']);

    $this->createBookType();
    $this->createBookField('field_title');
    $this->createBookField('field_full_title');
    $this->createBookField('field_author');
    $this->createBookField('field_isbn');
  }

  public function testAdoptBookListingCreatesLocalListingAndLinks(): void {
    $account = $this->createProductionAccount();
    $this->insertMirroredBookRows((int) $account->id(), '176582430935', '2024 September A01', '261186');

    $service = $this->container->get('bb_ebay_legacy_migration.adoption_service');
    $result = $service->adoptBookListing('176582430935', (int) $account->id());

    $listing = BbAiListing::load($result['local_listing_id']);

    $this->assertInstanceOf(BbAiListing::class, $listing);
    $this->assertSame('Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond', $listing->label());
    $this->assertSame('The Test Book', $listing->get('field_title')->value);
    $this->assertSame('Example Author', $listing->get('field_author')->value);
    $this->assertSame('9780123456789', $listing->get('field_isbn')->value);
    $this->assertSame('19.99', $listing->get('price')->value);
    $this->assertSame('like_new', $listing->get('condition_grade')->value);
    $this->assertSame('Looks unused and clean.', $listing->get('condition_note')->value);

    $skuRows = $this->container->get('entity_type.manager')
      ->getStorage('ai_listing_inventory_sku')
      ->loadByProperties(['listing' => $listing->id()]);
    $this->assertCount(1, $skuRows);

    $publicationRows = $this->container->get('entity_type.manager')
      ->getStorage('ai_marketplace_publication')
      ->loadByProperties(['listing' => $listing->id()]);
    $this->assertCount(1, $publicationRows);

    $publicationRow = reset($publicationRows);
    $this->assertSame('ebay', $publicationRow->get('marketplace_key')->value);
    $this->assertSame('published', $publicationRow->get('status')->value);
    $this->assertSame('FIXED_PRICE', $publicationRow->get('publication_type')->value);
    $this->assertSame('125857702011', $publicationRow->get('marketplace_publication_id')->value);
    $this->assertSame('176582430935', $publicationRow->get('marketplace_listing_id')->value);
    $this->assertSame('legacy_adopted', $publicationRow->get('source')->value);
    $this->assertSame(1727747606, (int) $publicationRow->get('marketplace_started_at')->value);

    $legacyLinkRow = $this->container->get('database')
      ->select('bb_ebay_legacy_listing_link', 'legacy_link')
      ->fields('legacy_link')
      ->condition('listing', (int) $listing->id())
      ->execute()
      ->fetchAssoc();

    $this->assertIsArray($legacyLinkRow);
    $this->assertSame('legacy_ebay_migrated', $legacyLinkRow['origin_type']);
    $this->assertSame('176582430935', $legacyLinkRow['ebay_listing_id']);
    $this->assertSame('1727747606', $legacyLinkRow['ebay_listing_started_at']);
    $this->assertSame('2024 September A01', $legacyLinkRow['source_sku']);
  }

  public function testAdoptBookListingRejectsAlreadyLinkedSku(): void {
    $account = $this->createProductionAccount();
    $this->insertMirroredBookRows((int) $account->id(), '176582430935', '2024 September A01', '261186');

    $service = $this->container->get('bb_ebay_legacy_migration.adoption_service');
    $service->adoptBookListing('176582430935', (int) $account->id());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('eBay listing 176582430935 has already been adopted.');

    $service->adoptBookListing('176582430935', (int) $account->id());
  }

  public function testAdoptBookListingRejectsNonBookCategory(): void {
    $account = $this->createProductionAccount();
    $this->insertMirroredBookRows((int) $account->id(), '176582430935', '2024 September A01', '261672');

    $service = $this->container->get('bb_ebay_legacy_migration.adoption_service');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is not eligible for adopt-book');

    $service->adoptBookListing('176582430935', (int) $account->id());
  }

  public function testAdoptGenericListingCreatesLocalGenericListingAndLinks(): void {
    $account = $this->createProductionAccount();
    $this->insertMirroredBookRows((int) $account->id(), '176577811710', '2024 September A01-M2', '261672');

    $service = $this->container->get('bb_ebay_legacy_migration.adoption_service');
    $result = $service->adoptGenericListing('176577811710', (int) $account->id());

    $listing = BbAiListing::load($result['local_listing_id']);
    $this->assertInstanceOf(BbAiListing::class, $listing);
    $this->assertSame('generic', $listing->bundle());
    $this->assertSame('Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond', $listing->label());

    $publicationRows = $this->container->get('entity_type.manager')
      ->getStorage('ai_marketplace_publication')
      ->loadByProperties(['listing' => $listing->id()]);
    $this->assertCount(1, $publicationRows);
    $publicationRow = reset($publicationRows);
    $this->assertSame('legacy_adopted', $publicationRow->get('source')->value);
  }

  public function testAdoptGenericListingRejectsBookCategory(): void {
    $account = $this->createProductionAccount();
    $this->insertMirroredBookRows((int) $account->id(), '176582430935', '2024 September A01', '261186');

    $service = $this->container->get('bb_ebay_legacy_migration.adoption_service');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is not eligible for adopt-generic');

    $service->adoptGenericListing('176582430935', (int) $account->id());
  }

  private function createBookType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

  private function createBookField(string $fieldName): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'bb_ai_listing',
      'type' => 'string',
      'settings' => [
        'max_length' => 255,
      ],
      'cardinality' => 1,
    ])->save();

    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'bb_ai_listing',
      'bundle' => 'book',
      'label' => $fieldName,
    ])->save();
  }

  private function createProductionAccount(): EbayAccount {
    $user = User::create([
      'name' => 'legacy_migration_owner',
    ]);
    $user->save();

    $account = EbayAccount::create([
      'label' => 'Primary eBay Account',
      'uid' => (int) $user->id(),
      'environment' => 'production',
      'access_token' => 'test-access-token',
      'refresh_token' => 'test-refresh-token',
      'expires_at' => time() + 3600,
    ]);
    $account->save();

    return $account;
  }

  private function insertMirroredBookRows(int $accountId, string $listingId, string $sku, string $categoryId): void {
    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => $accountId,
        'sku' => $sku,
        'locale' => 'en_AU',
        'title' => 'Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond',
        'description' => NULL,
        'condition' => 'USED_EXCELLENT',
        'condition_description' => 'Looks unused and clean.',
        'available_quantity' => 1,
        'aspects_json' => json_encode([
          'Book Title' => ['The Test Book'],
          'Author' => ['Example Author'],
          'ISBN' => ['9780123456789'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'image_urls_json' => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'raw_json' => json_encode(['sku' => $sku], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'last_seen' => time(),
      ])
      ->execute();

    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => $accountId,
        'offer_id' => '125857702011',
        'sku' => $sku,
        'marketplace_id' => 'EBAY_AU',
        'format' => 'FIXED_PRICE',
        'listing_description' => '<p>Legacy migrated description.</p>',
        'available_quantity' => 1,
        'price_value' => '19.99',
        'price_currency' => 'AUD',
        'listing_id' => $listingId,
        'category_id' => $categoryId,
        'listing_status' => 'ACTIVE',
        'status' => 'PUBLISHED',
        'raw_json' => json_encode(['offerId' => '125857702011'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'last_seen' => time(),
      ])
      ->execute();

    $this->container->get('database')->insert('bb_ebay_legacy_listing')
      ->fields([
        'account_id' => $accountId,
        'ebay_listing_id' => $listingId,
        'sku' => $sku,
        'title' => 'Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond',
        'ebay_listing_started_at' => 1727747606,
        'listing_status' => 'Active',
        'primary_category_id' => '261186',
        'raw_xml' => '<Item/>',
        'last_seen' => time(),
      ])
      ->execute();
  }

}
