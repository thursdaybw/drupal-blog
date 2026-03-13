<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_orders\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\marketplace_orders\Service\PickPackQueueQueryService;

/**
 * Verifies pick-pack queue read-model filtering and joins.
 */
final class PickPackQueueQueryServiceTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
    'ebay_infrastructure',
    'marketplace_orders',
  ];

  private PickPackQueueQueryService $queryService;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');

    $this->installSchema('marketplace_orders', [
      'marketplace_order',
      'marketplace_order_line',
      'marketplace_order_sync_state',
    ]);

    $this->queryService = $this->container->get(PickPackQueueQueryService::class);

    $this->seedListings();
    $this->seedOrders();
  }

  public function testQueryReturnsOnlyActionableRowsByDefault(): void {
    $result = $this->queryService->query();

    $this->assertSame(1, $result->getTotalRows());
    $this->assertCount(1, $result->getRows());

    $row = $result->getRows()[0];
    $this->assertSame('ORDER-ACTIONABLE', $row->getExternalOrderId());
    $this->assertSame('paid', $row->getPaymentStatus());
    $this->assertSame('not_started', $row->getFulfillmentStatus());
    $this->assertSame('new', $row->getWarehouseStatus());
    $this->assertSame('A1-SHELF', $row->getStorageLocation());
    $this->assertSame('The Yarns of Billy Borker', $row->getListingTitle());
  }

  public function testQueryCanReturnAllRowsWhenActionableFilterDisabled(): void {
    $result = $this->queryService->query([
      'actionable_only' => FALSE,
      'limit' => 20,
      'offset' => 0,
    ]);

    $this->assertSame(3, $result->getTotalRows());
    $this->assertCount(3, $result->getRows());
  }

  private function seedListings(): void {
    $database = $this->container->get('database');

    $database->insert('bb_ai_listing')
      ->fields([
        'uuid' => 'de647017-4054-4512-9025-4d12aad996ba',
        'status' => 'shelved',
        'ebay_title' => 'The Yarns of Billy Borker',
        'storage_location' => 'A1-SHELF',
        'listing_type' => 'book',
        'created' => 1773100800,
        'changed' => 1773100800,
      ])
      ->execute();
  }

  private function seedOrders(): void {
    $database = $this->container->get('database');

    $actionableOrderId = (int) $database->insert('marketplace_order')
      ->fields([
        'marketplace' => 'ebay',
        'external_order_id' => 'ORDER-ACTIONABLE',
        'status' => 'not_started',
        'payment_status' => 'paid',
        'fulfillment_status' => 'not_started',
        'ordered_at' => 1773200000,
        'buyer_handle' => 'buyer_actionable',
        'created' => 1773200000,
        'changed' => 1773200000,
      ])
      ->execute();

    $database->insert('marketplace_order_line')
      ->fields([
        'order_id' => $actionableOrderId,
        'external_line_id' => 'LINE-ACTIONABLE-1',
        'sku' => 'SKU-ACTIONABLE-1',
        'quantity' => 1,
        'title_snapshot' => 'The Yarns of Billy Borker',
        'price_snapshot' => '12.34',
        'listing_uuid' => 'de647017-4054-4512-9025-4d12aad996ba',
        'created' => 1773200000,
        'changed' => 1773200000,
      ])
      ->execute();

    $fulfilledOrderId = (int) $database->insert('marketplace_order')
      ->fields([
        'marketplace' => 'ebay',
        'external_order_id' => 'ORDER-FULFILLED',
        'status' => 'fulfilled',
        'payment_status' => 'paid',
        'fulfillment_status' => 'fulfilled',
        'ordered_at' => 1773200100,
        'buyer_handle' => 'buyer_fulfilled',
        'created' => 1773200100,
        'changed' => 1773200100,
      ])
      ->execute();

    $database->insert('marketplace_order_line')
      ->fields([
        'order_id' => $fulfilledOrderId,
        'external_line_id' => 'LINE-FULFILLED-1',
        'sku' => 'SKU-FULFILLED-1',
        'quantity' => 1,
        'title_snapshot' => 'Already shipped item',
        'price_snapshot' => '20.00',
        'listing_uuid' => NULL,
        'created' => 1773200100,
        'changed' => 1773200100,
      ])
      ->execute();

    $unpaidOrderId = (int) $database->insert('marketplace_order')
      ->fields([
        'marketplace' => 'ebay',
        'external_order_id' => 'ORDER-UNPAID',
        'status' => 'awaiting_payment',
        'payment_status' => 'awaiting_payment',
        'fulfillment_status' => 'not_started',
        'ordered_at' => 1773200200,
        'buyer_handle' => 'buyer_unpaid',
        'created' => 1773200200,
        'changed' => 1773200200,
      ])
      ->execute();

    $database->insert('marketplace_order_line')
      ->fields([
        'order_id' => $unpaidOrderId,
        'external_line_id' => 'LINE-UNPAID-1',
        'sku' => 'SKU-UNPAID-1',
        'quantity' => 1,
        'title_snapshot' => 'Awaiting payment item',
        'price_snapshot' => '8.00',
        'listing_uuid' => NULL,
        'created' => 1773200200,
        'changed' => 1773200200,
      ])
      ->execute();
  }

}
