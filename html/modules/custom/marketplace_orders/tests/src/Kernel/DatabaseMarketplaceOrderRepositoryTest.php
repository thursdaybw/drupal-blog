<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_orders\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\marketplace_orders\Contract\MarketplaceOrderRepositoryInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot;
use Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot;

/**
 * Verifies database order repository idempotent upsert behavior.
 */
final class DatabaseMarketplaceOrderRepositoryTest extends KernelTestBase {

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

  private MarketplaceOrderRepositoryInterface $repository;

  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('marketplace_orders', [
      'marketplace_order',
      'marketplace_order_line',
      'marketplace_order_sync_state',
    ]);

    $this->repository = $this->container->get(MarketplaceOrderRepositoryInterface::class);
  }

  public function testUpsertOrderIsIdempotentAndUpdatesExistingRows(): void {
    $firstSnapshot = $this->createSnapshot(
      status: 'awaiting_payment',
      paymentStatus: 'awaiting_payment',
      fulfillmentStatus: 'not_started',
      buyerHandle: 'buyer_a',
      payloadHash: 'hash_a',
      lineQuantityByLineId: [
        'line_1' => 1,
      ],
    );

    $firstOrderId = $this->repository->upsertOrder($firstSnapshot);

    $secondSnapshot = $this->createSnapshot(
      status: 'paid',
      paymentStatus: 'paid',
      fulfillmentStatus: 'in_progress',
      buyerHandle: 'buyer_b',
      payloadHash: 'hash_b',
      lineQuantityByLineId: [
        'line_1' => 3,
        'line_2' => 2,
      ],
    );

    $secondOrderId = $this->repository->upsertOrder($secondSnapshot);

    $this->assertSame($firstOrderId, $secondOrderId, 'Order upsert keeps one local order row.');
    $this->assertSame(1, $this->countRows('marketplace_order'), 'Only one order row exists after repeated upsert.');
    $this->assertSame(2, $this->countRows('marketplace_order_line'), 'Line upsert inserts new lines and updates existing lines.');

    $orderRow = $this->container->get('database')
      ->select('marketplace_order', 'o')
      ->fields('o')
      ->condition('id', $firstOrderId)
      ->execute()
      ->fetchAssoc();

    $this->assertSame('paid', $orderRow['status']);
    $this->assertSame('paid', $orderRow['payment_status']);
    $this->assertSame('in_progress', $orderRow['fulfillment_status']);
    $this->assertSame('buyer_b', $orderRow['buyer_handle']);
    $this->assertSame('hash_b', $orderRow['payload_hash']);

    $lineOneRow = $this->container->get('database')
      ->select('marketplace_order_line', 'l')
      ->fields('l')
      ->condition('order_id', $firstOrderId)
      ->condition('external_line_id', 'line_1')
      ->execute()
      ->fetchAssoc();

    $this->assertSame('3', (string) $lineOneRow['quantity']);

    $lineTwoRow = $this->container->get('database')
      ->select('marketplace_order_line', 'l')
      ->fields('l')
      ->condition('order_id', $firstOrderId)
      ->condition('external_line_id', 'line_2')
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($lineTwoRow, 'Second line was inserted on subsequent upsert.');
  }

  /**
   * @param array<string, int> $lineQuantityByLineId
   */
  private function createSnapshot(
    string $status,
    string $paymentStatus,
    string $fulfillmentStatus,
    string $buyerHandle,
    string $payloadHash,
    array $lineQuantityByLineId,
  ): MarketplaceOrderSnapshot {
    $lines = [];

    foreach ($lineQuantityByLineId as $externalLineId => $quantity) {
      $lines[] = new MarketplaceOrderLineSnapshot(
        externalLineId: $externalLineId,
        sku: 'SKU-123',
        quantity: $quantity,
        titleSnapshot: 'The Yarns of Billy Borker',
        priceSnapshot: '12.34',
        listingUuid: 'de647017-4054-4512-9025-4d12aad996ba',
        rawJson: '{"line":"' . $externalLineId . '"}',
      );
    }

    return new MarketplaceOrderSnapshot(
      marketplace: 'ebay',
      externalOrderId: 'ORDER-1',
      status: $status,
      paymentStatus: $paymentStatus,
      fulfillmentStatus: $fulfillmentStatus,
      orderedAt: 1773145358,
      buyerHandle: $buyerHandle,
      totalsJson: '{"total":"12.34"}',
      payloadHash: $payloadHash,
      rawJson: '{"order":"ORDER-1"}',
      lines: $lines,
    );
  }

  private function countRows(string $tableName): int {
    return (int) $this->container->get('database')
      ->select($tableName, 't')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
