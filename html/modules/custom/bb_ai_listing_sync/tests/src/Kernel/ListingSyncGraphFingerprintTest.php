<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ai_listing_sync\Kernel;

use Drupal\ai_listing\Entity\AiBookBundleItem;
use Drupal\ai_listing\Entity\AiListingInventorySku;
use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Entity\ListingImage;
use Drupal\bb_ai_listing_sync\Contract\ListingSyncGraphBuilderInterface;
use Drupal\bb_ai_listing_sync\Service\ListingSyncGraphFingerprintService;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;

/**
 * Proves graph traversal and fingerprint drift for bb_ai_listing sync.
 */
final class ListingSyncGraphFingerprintTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'options',
    'dynamic_entity_reference',
    'bb_platform',
    'ai_listing',
    'bb_ai_listing_sync',
  ];

  private ListingSyncGraphBuilderInterface $graphBuilder;

  private ListingSyncGraphFingerprintService $fingerprintService;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_book_bundle_item');
    $this->installEntitySchema('ai_listing_inventory_sku');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installEntitySchema('listing_image');
    $this->createLegacyMirrorTables();
    $this->installConfig(['ai_listing']);

    $this->ensureListingTypeExists('book', 'Book');

    $this->graphBuilder = $this->container->get('bb_ai_listing_sync.export_graph_builder');
    $this->fingerprintService = $this->container->get('bb_ai_listing_sync.graph_fingerprint');
  }

  public function testGraphTraversalIncludesExpectedRelationships(): void {
    $fixture = $this->createFixtureListingGraph();

    $graph = $this->graphBuilder->buildForListing($fixture['listing']);
    $counts = $graph->counts();

    $this->assertSame(1, $counts['bb_ai_listing']);
    $this->assertSame(1, $counts['ai_book_bundle_item']);
    $this->assertSame(1, $counts['ai_listing_inventory_sku']);
    $this->assertSame(1, $counts['ai_marketplace_publication']);
    $this->assertSame(2, $counts['listing_image']);
    $this->assertSame(2, $counts['file']);
    $this->assertSame(8, $graph->totalEntities());
    $this->assertSame(8, $graph->totalUuids());
  }

  public function testFingerprintChangesWhenEntityOrLegacyDataChanges(): void {
    $fixture = $this->createFixtureListingGraph();

    $graphBefore = $this->graphBuilder->buildForListing($fixture['listing']);
    $fingerprintBefore = $this->fingerprintService->fingerprintGraph($graphBefore);

    $graphAgain = $this->graphBuilder->buildForListing($fixture['listing']);
    $fingerprintAgain = $this->fingerprintService->fingerprintGraph($graphAgain);
    $this->assertSame($fingerprintBefore, $fingerprintAgain, 'Fingerprint must be stable for unchanged graph.');

    $rootImage = $fixture['root_image'];
    $rootImage->set('weight', 9);
    $rootImage->save();

    $graphAfterEntityChange = $this->graphBuilder->buildForListing($fixture['listing']);
    $fingerprintAfterEntityChange = $this->fingerprintService->fingerprintGraph($graphAfterEntityChange);
    $this->assertNotSame($fingerprintBefore, $fingerprintAfterEntityChange, 'Fingerprint must change when related entity data changes.');

    $this->container->get('database')->update('bb_ebay_legacy_listing')
      ->fields([
        'title' => 'Legacy title changed for fingerprint drift test',
      ])
      ->condition('account_id', 1)
      ->condition('ebay_listing_id', '176582430935')
      ->execute();

    $graphAfterLegacyChange = $this->graphBuilder->buildForListing($fixture['listing']);
    $fingerprintAfterLegacyChange = $this->fingerprintService->fingerprintGraph($graphAfterLegacyChange);
    $this->assertNotSame($fingerprintAfterEntityChange, $fingerprintAfterLegacyChange, 'Fingerprint must change when legacy sidecar payload changes.');
  }

  public function testFingerprintIgnoresVolatileTimestampDrift(): void {
    $fixture = $this->createFixtureListingGraph();

    $graphBefore = $this->graphBuilder->buildForListing($fixture['listing']);
    $fingerprintBefore = $this->fingerprintService->fingerprintGraph($graphBefore);

    $listing = $fixture['listing'];
    $listing->setChangedTime(((int) $listing->getChangedTime()) + 3600);
    $listing->save();

    $rootImage = $fixture['root_image'];
    $rootImage->setChangedTime(((int) $rootImage->getChangedTime()) + 3600);
    $rootImage->save();

    $this->container->get('database')->update('bb_ebay_legacy_listing_link')
      ->fields([
        'created' => time() + 60,
        'changed' => time() + 120,
      ])
      ->condition('listing', (int) $listing->id())
      ->execute();

    $this->container->get('database')->update('bb_ebay_legacy_listing')
      ->fields([
        'last_seen' => time() + 180,
      ])
      ->condition('account_id', 1)
      ->condition('ebay_listing_id', '176582430935')
      ->execute();

    $graphAfter = $this->graphBuilder->buildForListing($fixture['listing']);
    $fingerprintAfter = $this->fingerprintService->fingerprintGraph($graphAfter);

    $this->assertSame($fingerprintBefore, $fingerprintAfter, 'Fingerprint must ignore volatile timestamp drift caused by import or entity resave.');
  }

  public function testGraphTraversalExcludesUnrelatedListingOwnedImages(): void {
    $fixture = $this->createFixtureListingGraph();

    $unrelatedListing = BbAiListing::create([
      'listing_type' => 'book',
      'ebay_title' => 'Unrelated listing',
      'status' => 'ready_for_review',
      'condition_grade' => 'good',
      'storage_location' => 'BDMAA04',
      'listing_code' => 'UNRELATED01',
      'price' => '9.99',
    ]);
    $unrelatedListing->set('description', 'Unrelated listing description');
    $unrelatedListing->save();

    $unrelatedFile = File::create([
      'uri' => 'public://ai-listings/unrelated/unrelated.jpg',
      'filename' => 'unrelated.jpg',
      'status' => 1,
    ]);
    $unrelatedFile->save();

    $unrelatedImage = ListingImage::create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => (int) $unrelatedListing->id(),
      ],
      'file' => (int) $unrelatedFile->id(),
      'is_metadata_source' => FALSE,
      'weight' => 0,
    ]);
    $unrelatedImage->save();

    $graph = $this->graphBuilder->buildForListing($fixture['listing']);
    $counts = $graph->counts();

    $this->assertSame(2, $counts['listing_image'], 'Target listing graph must not include unrelated listing-owned images.');

    $listingImageUuids = [];
    foreach ($graph->entitiesByType()['listing_image'] as $listingImage) {
      $listingImageUuids[] = (string) $listingImage->uuid();
    }

    $this->assertNotContains((string) $unrelatedImage->uuid(), $listingImageUuids);
  }

  private function ensureListingTypeExists(string $id, string $label): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing_type');
    if ($storage->load($id) instanceof BbAiListingType) {
      return;
    }

    BbAiListingType::create([
      'id' => $id,
      'label' => $label,
      'description' => $label . ' listing type',
    ])->save();
  }

  private function createLegacyMirrorTables(): void {
    $schema = $this->container->get('database')->schema();

    if (!$schema->tableExists('bb_ebay_legacy_listing_link')) {
      $schema->createTable('bb_ebay_legacy_listing_link', [
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
          ],
          'listing' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'account_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'origin_type' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
          ],
          'ebay_listing_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
          ],
          'ebay_listing_started_at' => [
            'type' => 'int',
            'not null' => FALSE,
          ],
          'source_sku' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => FALSE,
          ],
          'created' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'changed' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
        ],
        'primary key' => ['id'],
      ]);
    }

    if (!$schema->tableExists('bb_ebay_legacy_listing')) {
      $schema->createTable('bb_ebay_legacy_listing', [
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
          ],
          'account_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'ebay_listing_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
          ],
          'sku' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => FALSE,
          ],
          'title' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => FALSE,
          ],
          'ebay_listing_started_at' => [
            'type' => 'int',
            'not null' => FALSE,
          ],
          'listing_status' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => FALSE,
          ],
          'primary_category_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => FALSE,
          ],
          'raw_xml' => [
            'type' => 'text',
            'not null' => FALSE,
            'size' => 'big',
          ],
          'last_seen' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
        ],
        'primary key' => ['id'],
      ]);
    }
  }

  /**
   * @return array{
   *   listing: \Drupal\ai_listing\Entity\BbAiListing,
   *   root_image: \Drupal\ai_listing\Entity\ListingImage
   * }
   */
  private function createFixtureListingGraph(): array {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'ebay_title' => 'Graph fixture listing',
      'status' => 'ready_for_review',
      'condition_grade' => 'good',
      'storage_location' => 'BDMAA03',
      'listing_code' => '75B1B776',
      'price' => '12.34',
    ]);
    $listing->set('description', 'Fixture description');
    $listing->save();

    $bundleItem = AiBookBundleItem::create([
      'bundle_listing' => (int) $listing->id(),
      'weight' => 0,
      'title' => 'Bundle item title',
      'condition_grade' => 'good',
    ]);
    $bundleItem->save();

    $inventorySku = AiListingInventorySku::create([
      'listing' => (int) $listing->id(),
      'sku' => '2026 Mar BDMAA03 ai-book-75B1B776',
      'status' => 'active',
    ]);
    $inventorySku->save();

    $publication = AiMarketplacePublication::create([
      'listing' => (int) $listing->id(),
      'inventory_sku' => (int) $inventorySku->id(),
      'inventory_sku_value' => '2026 Mar BDMAA03 ai-book-75B1B776',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_publication_id' => '125857702011',
      'marketplace_listing_id' => '176582430935',
      'source' => 'legacy_adopted',
    ]);
    $publication->save();

    $fileA = File::create([
      'uri' => 'public://ai-listings/75b1b776-51ae-41c4-972b-4eced2e95cf7/fixture-a.jpg',
      'filename' => 'fixture-a.jpg',
      'status' => 1,
    ]);
    $fileA->save();

    $fileB = File::create([
      'uri' => 'public://ai-listings/75b1b776-51ae-41c4-972b-4eced2e95cf7/fixture-b.jpg',
      'filename' => 'fixture-b.jpg',
      'status' => 1,
    ]);
    $fileB->save();

    $rootImage = ListingImage::create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => (int) $listing->id(),
      ],
      'file' => (int) $fileA->id(),
      'is_metadata_source' => TRUE,
      'weight' => 0,
    ]);
    $rootImage->save();

    $bundleImage = ListingImage::create([
      'owner' => [
        'target_type' => 'ai_book_bundle_item',
        'target_id' => (int) $bundleItem->id(),
      ],
      'file' => (int) $fileB->id(),
      'is_metadata_source' => FALSE,
      'weight' => 1,
    ]);
    $bundleImage->save();

    $this->container->get('database')->insert('bb_ebay_legacy_listing_link')
      ->fields([
        'listing' => (int) $listing->id(),
        'account_id' => 1,
        'origin_type' => 'legacy_ebay_migrated',
        'ebay_listing_id' => '176582430935',
        'ebay_listing_started_at' => 1727747606,
        'source_sku' => '2026 Mar BDMAA03 ai-book-75B1B776',
        'created' => time(),
        'changed' => time(),
      ])
      ->execute();

    $this->container->get('database')->insert('bb_ebay_legacy_listing')
      ->fields([
        'account_id' => 1,
        'ebay_listing_id' => '176582430935',
        'sku' => '2026 Mar BDMAA03 ai-book-75B1B776',
        'title' => 'Legacy fixture title',
        'ebay_listing_started_at' => 1727747606,
        'listing_status' => 'ACTIVE',
        'primary_category_id' => '261186',
        'raw_xml' => '<Item/>',
        'last_seen' => time(),
      ])
      ->execute();

    return [
      'listing' => $listing,
      'root_image' => $rootImage,
    ];
  }

}
