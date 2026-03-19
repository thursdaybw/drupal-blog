<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_listing\Form\AiListingStockCullPickerForm;
use Drupal\ai_listing\Service\StockCullSelectionStore;
use Drupal\Core\Form\FormState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Verifies the stock cull picker workflow surface.
 */
final class AiListingStockCullPickerFormTest extends KernelTestBase {

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

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installEntitySchema('file');
    $this->installEntitySchema('listing_image');
  }

  public function testBuildGroupsRowsByLocation(): void {
    $this->createPublishedListing('Shelf A title', '10.00', 'SHELF-A', 'SKU-A', '177100000001', 1700000000);
    $this->createPublishedListing('Shelf B title', '12.00', 'SHELF-B', 'SKU-B', '177100000002', 1700000001);

    $request = Request::create('/admin/ai-listings/reports/stock-cull/picker', 'GET');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $form = AiListingStockCullPickerForm::create($this->container)->buildForm([], new FormState());
    $markup = (string) $this->container->get('renderer')->renderRoot($form);

    $this->assertStringContainsString('SHELF-A (1 candidates, 0 marked)', $markup);
    $this->assertStringContainsString('SHELF-B (1 candidates, 0 marked)', $markup);
    $this->assertStringContainsString('Shelf A title', $markup);
    $this->assertStringContainsString('Shelf B title', $markup);
  }

  public function testSubmitMarkAndUnmarkSelected(): void {
    $listing = $this->createPublishedListing('Mark me', '10.00', 'SHELF-A', 'SKU-MARK', '177100000010', 1700000000);

    $request = Request::create('/admin/ai-listings/reports/stock-cull/picker', 'GET', ['listing_type' => 'book']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $formObject = AiListingStockCullPickerForm::create($this->container);
    $formState = new FormState();
    $formState->setValue('listing_type', 'book');
    $formState->setValue('locations', [
      'location_test' => [
        'table' => [
          (string) $listing->id() => ['selected' => 1],
        ],
      ],
    ]);

    $form = [];
    $formObject->submitMarkSelected($form, $formState);

    /** @var \Drupal\ai_listing\Service\StockCullSelectionStore $store */
    $store = $this->container->get('ai_listing.stock_cull_selection_store');
    $this->assertSame(StockCullSelectionStore::STATUS_MARKED_FOR_CULL, $store->getStatuses([(int) $listing->id()])[(int) $listing->id()]);

    $formObject->submitUnmarkSelected($form, $formState);
    $this->assertSame(StockCullSelectionStore::STATUS_NOT_MARKED, $store->getStatuses([(int) $listing->id()])[(int) $listing->id()]);
  }

  private function createPublishedListing(string $title, string $price, string $location, string $sku, string $ebayId, int $listedAt) {
    $listing = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing')->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => $title,
      'price' => $price,
      'storage_location' => $location,
      'listing_code' => strtoupper(substr(md5($title), 0, 8)),
    ]);
    $listing->save();

    $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication')->create([
      'listing' => (int) $listing->id(),
      'inventory_sku_value' => $sku,
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => $ebayId,
      'source' => 'local_publish',
      'published_at' => $listedAt,
    ])->save();

    return $listing;
  }

}
