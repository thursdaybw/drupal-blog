<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\marketplace_orders\Contract\MarketplaceOrderRepositoryInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot;
use Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot;

/**
 * Database-backed idempotent repository for marketplace orders.
 */
final class DatabaseMarketplaceOrderRepository implements MarketplaceOrderRepositoryInterface {

  public function __construct(
    private readonly Connection $connection,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function upsertOrder(MarketplaceOrderSnapshot $order): int {
    $transaction = $this->connection->startTransaction();

    try {
      $orderId = $this->upsertOrderHeader($order);
      $this->upsertOrderLines($orderId, $order->getLines());

      return $orderId;
    }
    catch (\Throwable $exception) {
      unset($transaction);
      throw $exception;
    }
  }

  private function upsertOrderHeader(MarketplaceOrderSnapshot $order): int {
    $existingOrderId = $this->loadOrderId($order->getMarketplace(), $order->getExternalOrderId());

    if ($existingOrderId !== NULL) {
      $this->updateOrderHeader($existingOrderId, $order);

      return $existingOrderId;
    }

    return $this->insertOrderHeader($order);
  }

  private function loadOrderId(string $marketplace, string $externalOrderId): ?int {
    $value = $this->connection
      ->select('marketplace_order', 'o')
      ->fields('o', ['id'])
      ->condition('marketplace', $marketplace)
      ->condition('external_order_id', $externalOrderId)
      ->execute()
      ->fetchField();

    if ($value === FALSE || $value === NULL) {
      return NULL;
    }

    return (int) $value;
  }

  private function updateOrderHeader(int $orderId, MarketplaceOrderSnapshot $order): void {
    $this->connection
      ->update('marketplace_order')
      ->fields([
        'status' => $order->getStatus(),
        'payment_status' => $order->getPaymentStatus(),
        'fulfillment_status' => $order->getFulfillmentStatus(),
        'ordered_at' => $order->getOrderedAt(),
        'buyer_handle' => $order->getBuyerHandle(),
        'totals_json' => $order->getTotalsJson(),
        'payload_hash' => $order->getPayloadHash(),
        'raw_json' => $order->getRawJson(),
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $orderId)
      ->execute();
  }

  private function insertOrderHeader(MarketplaceOrderSnapshot $order): int {
    $requestTime = $this->time->getRequestTime();

    return (int) $this->connection
      ->insert('marketplace_order')
      ->fields([
        'marketplace' => $order->getMarketplace(),
        'external_order_id' => $order->getExternalOrderId(),
        'status' => $order->getStatus(),
        'payment_status' => $order->getPaymentStatus(),
        'fulfillment_status' => $order->getFulfillmentStatus(),
        'ordered_at' => $order->getOrderedAt(),
        'buyer_handle' => $order->getBuyerHandle(),
        'totals_json' => $order->getTotalsJson(),
        'payload_hash' => $order->getPayloadHash(),
        'raw_json' => $order->getRawJson(),
        'created' => $requestTime,
        'changed' => $requestTime,
      ])
      ->execute();
  }

  /**
   * @param \Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot[] $lines
   */
  private function upsertOrderLines(int $orderId, array $lines): void {
    foreach ($lines as $line) {
      $this->upsertOrderLine($orderId, $line);
    }
  }

  private function upsertOrderLine(int $orderId, MarketplaceOrderLineSnapshot $line): void {
    $existingLineId = $this->loadOrderLineId($orderId, $line->getExternalLineId());

    if ($existingLineId !== NULL) {
      $this->updateOrderLine($existingLineId, $line);

      return;
    }

    $this->insertOrderLine($orderId, $line);
  }

  private function loadOrderLineId(int $orderId, string $externalLineId): ?int {
    $value = $this->connection
      ->select('marketplace_order_line', 'l')
      ->fields('l', ['id'])
      ->condition('order_id', $orderId)
      ->condition('external_line_id', $externalLineId)
      ->execute()
      ->fetchField();

    if ($value === FALSE || $value === NULL) {
      return NULL;
    }

    return (int) $value;
  }

  private function updateOrderLine(int $lineId, MarketplaceOrderLineSnapshot $line): void {
    $this->connection
      ->update('marketplace_order_line')
      ->fields([
        'sku' => $line->getSku(),
        'quantity' => $this->normalizeQuantity($line->getQuantity()),
        'title_snapshot' => $line->getTitleSnapshot(),
        'price_snapshot' => $line->getPriceSnapshot(),
        'listing_uuid' => $line->getListingUuid(),
        'raw_json' => $line->getRawJson(),
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $lineId)
      ->execute();
  }

  private function insertOrderLine(int $orderId, MarketplaceOrderLineSnapshot $line): void {
    $requestTime = $this->time->getRequestTime();

    $this->connection
      ->insert('marketplace_order_line')
      ->fields([
        'order_id' => $orderId,
        'external_line_id' => $line->getExternalLineId(),
        'sku' => $line->getSku(),
        'quantity' => $this->normalizeQuantity($line->getQuantity()),
        'title_snapshot' => $line->getTitleSnapshot(),
        'price_snapshot' => $line->getPriceSnapshot(),
        'listing_uuid' => $line->getListingUuid(),
        'warehouse_status' => 'new',
        'raw_json' => $line->getRawJson(),
        'created' => $requestTime,
        'changed' => $requestTime,
      ])
      ->execute();
  }

  private function normalizeQuantity(int $quantity): int {
    if ($quantity < 1) {
      return 1;
    }

    return $quantity;
  }

}
