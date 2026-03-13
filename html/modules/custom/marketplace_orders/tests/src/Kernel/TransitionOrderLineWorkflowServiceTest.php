<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_orders\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\marketplace_orders\Contract\OrderLineWorkflowRepositoryInterface;
use Drupal\marketplace_orders\Service\TransitionOrderLineWorkflowService;

/**
 * Verifies monotonic and idempotent workflow transitions on order lines.
 */
final class TransitionOrderLineWorkflowServiceTest extends KernelTestBase {

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

  private TransitionOrderLineWorkflowService $transitionService;
  private OrderLineWorkflowRepositoryInterface $workflowRepository;
  private int $orderLineId;

  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('marketplace_orders', [
      'marketplace_order',
      'marketplace_order_line',
      'marketplace_order_sync_state',
    ]);

    $this->transitionService = $this->container->get(TransitionOrderLineWorkflowService::class);
    $this->workflowRepository = $this->container->get(OrderLineWorkflowRepositoryInterface::class);
    $this->orderLineId = $this->seedOrderLine();
  }

  public function testTransitionSequenceIsMonotonicAndStoresAuditValues(): void {
    $picked = $this->transitionService->transition($this->orderLineId, 'picked', 7);
    $this->assertSame('picked', $picked->getWarehouseStatus());
    $this->assertNotNull($picked->getPickedAt());
    $this->assertSame(7, $picked->getPickedByUid());
    $this->assertNull($picked->getPackedAt());

    $packed = $this->transitionService->transition($this->orderLineId, 'packed', 8);
    $this->assertSame('packed', $packed->getWarehouseStatus());
    $this->assertNotNull($packed->getPackedAt());
    $this->assertSame(8, $packed->getPackedByUid());

    $labelPurchased = $this->transitionService->transition($this->orderLineId, 'label_purchased', 9);
    $this->assertSame('label_purchased', $labelPurchased->getWarehouseStatus());
    $this->assertNotNull($labelPurchased->getLabelPurchasedAt());
    $this->assertSame(9, $labelPurchased->getLabelPurchasedByUid());

    $dispatched = $this->transitionService->transition($this->orderLineId, 'dispatched', 10);
    $this->assertSame('dispatched', $dispatched->getWarehouseStatus());
    $this->assertNotNull($dispatched->getDispatchedAt());
    $this->assertSame(10, $dispatched->getDispatchedByUid());
  }

  public function testRepeatedActionIsIdempotent(): void {
    $first = $this->transitionService->transition($this->orderLineId, 'picked', 7);
    $second = $this->transitionService->transition($this->orderLineId, 'picked', 9);

    $this->assertSame('picked', $second->getWarehouseStatus());
    $this->assertSame($first->getPickedAt(), $second->getPickedAt());
    $this->assertSame(7, $second->getPickedByUid());
  }

  public function testSkippingRequiredTransitionThrowsException(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid transition');

    $this->transitionService->transition($this->orderLineId, 'packed', 8);
  }

  public function testRepositoryPersistsTransitionedState(): void {
    $this->transitionService->transition($this->orderLineId, 'picked', 7);
    $saved = $this->workflowRepository->loadByOrderLineId($this->orderLineId);

    $this->assertNotNull($saved);
    $this->assertSame('picked', $saved->getWarehouseStatus());
    $this->assertSame(7, $saved->getPickedByUid());
  }

  private function seedOrderLine(): int {
    $database = $this->container->get('database');

    $orderId = (int) $database->insert('marketplace_order')
      ->fields([
        'marketplace' => 'ebay',
        'external_order_id' => 'ORDER-WORKFLOW-1',
        'status' => 'not_started',
        'payment_status' => 'paid',
        'fulfillment_status' => 'not_started',
        'ordered_at' => 1773200000,
        'buyer_handle' => 'buyer_workflow',
        'created' => 1773200000,
        'changed' => 1773200000,
      ])
      ->execute();

    return (int) $database->insert('marketplace_order_line')
      ->fields([
        'order_id' => $orderId,
        'external_line_id' => 'LINE-WORKFLOW-1',
        'sku' => 'SKU-WORKFLOW-1',
        'quantity' => 1,
        'title_snapshot' => 'Workflow line',
        'price_snapshot' => '11.11',
        'listing_uuid' => NULL,
        'warehouse_status' => 'new',
        'created' => 1773200000,
        'changed' => 1773200000,
      ])
      ->execute();
  }

}
