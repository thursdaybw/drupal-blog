<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_orders\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\marketplace_orders\Controller\PickPackQueueController;
use Drupal\Tests\ai_listing\Traits\InstallsBbAiListingKernelSchemaTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the pick-pack controller can be built from the container and render.
 */
final class PickPackQueueControllerTest extends KernelTestBase {

  use InstallsBbAiListingKernelSchemaTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'taxonomy',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
    'ebay_infrastructure',
    'marketplace_orders',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installBbAiListingKernelSchema();
    $this->installSchema('marketplace_orders', [
      'marketplace_order',
      'marketplace_order_line',
      'marketplace_order_sync_state',
    ]);

    $this->seedListing();
    $this->seedOrder();
  }

  public function testControllerBuildReturnsQueuePageStructure(): void {
    $controller = PickPackQueueController::create($this->container);
    $request = Request::create('/admin/ai-listings/orders/pick-pack');

    $build = $controller->build($request);

    $this->assertIsArray($build);
    $this->assertArrayHasKey('table', $build);
    $this->assertArrayHasKey('mobile_cards', $build);
    $this->assertArrayHasKey('filters', $build);
    $this->assertArrayHasKey('#header', $build['table']);
    $this->assertArrayHasKey('#rows', $build['table']);
    $this->assertCount(1, $build['table']['#rows']);
    $this->assertArrayHasKey('actions', $build['table']['#rows'][0]);
    $this->assertIsArray($build['table']['#rows'][0]['actions']);
    $this->assertArrayHasKey('data', $build['table']['#rows'][0]['actions']);
    $this->assertArrayHasKey('items', $build['mobile_cards']);
    $this->assertArrayHasKey('fetch', $build['filters']);
  }

  public function testControllerBuildRendersWithoutWarnings(): void {
    $controller = PickPackQueueController::create($this->container);
    $request = Request::create('/admin/ai-listings/orders/pick-pack');

    $build = $controller->build($request);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Showing 1 of 1 rows', $markup);
    $this->assertStringContainsString('ORDER-CONTROLLER-1', $markup);
    $this->assertStringContainsString('Fetch orders', $markup);
    $this->assertStringContainsString('marketplace-orders-mobile-card', $markup);
    $this->assertStringContainsString('Qty 1', $markup);
    $this->assertStringContainsString('Picked', $markup);
    $this->assertStringNotContainsString('Packed', $markup);
    $this->assertStringNotContainsString('Label Purchased', $markup);
    $this->assertStringNotContainsString('Dispatched', $markup);
  }

  private function seedListing(): void {
    $this->container->get('database')
      ->insert('bb_ai_listing')
      ->fields([
        'uuid' => '1aaab553-c0a0-49d8-8264-4fe05b93675c',
        'status' => 'shelved',
        'ebay_title' => 'The House of God by Samuel Shem Paperback Book',
        'storage_location' => 'BDMCC03',
        'listing_type' => 'book',
        'created' => 1773340000,
        'changed' => 1773340000,
      ])
      ->execute();
  }

  private function seedOrder(): void {
    $database = $this->container->get('database');

    $orderId = (int) $database->insert('marketplace_order')
      ->fields([
        'marketplace' => 'ebay',
        'external_order_id' => 'ORDER-CONTROLLER-1',
        'status' => 'not_started',
        'payment_status' => 'paid',
        'fulfillment_status' => 'not_started',
        'ordered_at' => 1773349080,
        'buyer_handle' => 'buyer_controller',
        'created' => 1773349080,
        'changed' => 1773349080,
      ])
      ->execute();

    $database->insert('marketplace_order_line')
      ->fields([
        'order_id' => $orderId,
        'external_line_id' => 'LINE-CONTROLLER-1',
        'sku' => '2026 Mar BDMCC03 ai-book-1AAAB553',
        'quantity' => 1,
        'title_snapshot' => 'The House of God by Samuel Shem Paperback Book',
        'price_snapshot' => '14.66',
        'listing_uuid' => '1aaab553-c0a0-49d8-8264-4fe05b93675c',
        'warehouse_status' => 'new',
        'created' => 1773349080,
        'changed' => 1773349080,
      ])
      ->execute();
  }

}
