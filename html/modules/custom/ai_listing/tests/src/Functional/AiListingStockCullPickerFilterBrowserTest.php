<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Functional;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies browser-level filter submission for the stock cull picker.
 *
 * @group ai_listing
 */
final class AiListingStockCullPickerFilterBrowserTest extends BrowserTestBase {

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
  ];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();

    $this->drupalLogin($this->drupalCreateUser([
      'administer ai listings',
      'access administration pages',
    ]));
  }

  public function testPickerFilterSubmitPreservesQueryString(): void {
    $cheapOld = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Cheap old listing',
      'price' => '10.00',
      'storage_location' => 'SHELF-A',
      'listing_code' => 'CHEAPOLD',
    ]);
    $cheapOld->save();

    $expensiveNew = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Expensive new listing',
      'price' => '30.00',
      'storage_location' => 'SHELF-B',
      'listing_code' => 'EXPNEW',
    ]);
    $expensiveNew->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $cheapOld->id(),
      'inventory_sku_value' => 'SKU-CHEAPOLD',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000100001',
      'source' => 'local_publish',
      'published_at' => strtotime('2025-06-01 12:00:00'),
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $expensiveNew->id(),
      'inventory_sku_value' => 'SKU-EXPNEW',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000100002',
      'source' => 'local_publish',
      'published_at' => strtotime('2025-08-01 12:00:00'),
    ])->save();

    $this->drupalGet('/admin/ai-listings/reports/stock-cull/picker');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Total matching listings: 2');

    $this->submitForm([
      'Max price' => '20',
      'Listed on or before' => '2025-07-01',
    ], 'Apply filters');

    $currentUrl = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('/admin/ai-listings/reports/stock-cull/picker?', $currentUrl);
    $this->assertStringContainsString('max_price=20.00', $currentUrl);
    $this->assertStringContainsString('listed_before=2025-07-01', $currentUrl);
    $this->assertSession()->fieldValueEquals('Max price', '20.00');
    $this->assertSession()->fieldValueEquals('Listed on or before', '2025-07-01');
    $this->assertSession()->pageTextContains('Total matching listings: 1');
    $this->assertSession()->pageTextContains('Cheap old listing');
    $this->assertSession()->pageTextNotContains('Expensive new listing');
  }

}
