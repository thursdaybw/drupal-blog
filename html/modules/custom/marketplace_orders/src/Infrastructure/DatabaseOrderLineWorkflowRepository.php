<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure;

use Drupal\Core\Database\Connection;
use Drupal\marketplace_orders\Contract\OrderLineWorkflowRepositoryInterface;
use Drupal\marketplace_orders\Model\OrderLineWorkflowState;

/**
 * Database-backed workflow state repository for order lines.
 */
final class DatabaseOrderLineWorkflowRepository implements OrderLineWorkflowRepositoryInterface {

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadByOrderLineId(int $orderLineId): ?OrderLineWorkflowState {
    $record = $this->connection
      ->select('marketplace_order_line', 'line')
      ->fields('line', [
        'id',
        'warehouse_status',
        'picked_at',
        'picked_by_uid',
        'packed_at',
        'packed_by_uid',
        'label_purchased_at',
        'label_purchased_by_uid',
        'dispatched_at',
        'dispatched_by_uid',
      ])
      ->condition('id', $orderLineId)
      ->execute()
      ->fetchAssoc();

    if ($record === FALSE || $record === NULL) {
      return NULL;
    }

    return new OrderLineWorkflowState(
      orderLineId: (int) $record['id'],
      warehouseStatus: (string) ($record['warehouse_status'] ?? 'new'),
      pickedAt: $this->toNullableInt($record['picked_at'] ?? NULL),
      pickedByUid: $this->toNullableInt($record['picked_by_uid'] ?? NULL),
      packedAt: $this->toNullableInt($record['packed_at'] ?? NULL),
      packedByUid: $this->toNullableInt($record['packed_by_uid'] ?? NULL),
      labelPurchasedAt: $this->toNullableInt($record['label_purchased_at'] ?? NULL),
      labelPurchasedByUid: $this->toNullableInt($record['label_purchased_by_uid'] ?? NULL),
      dispatchedAt: $this->toNullableInt($record['dispatched_at'] ?? NULL),
      dispatchedByUid: $this->toNullableInt($record['dispatched_by_uid'] ?? NULL),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function save(OrderLineWorkflowState $state): void {
    $this->connection
      ->update('marketplace_order_line')
      ->fields([
        'warehouse_status' => $state->getWarehouseStatus(),
        'picked_at' => $state->getPickedAt(),
        'picked_by_uid' => $state->getPickedByUid(),
        'packed_at' => $state->getPackedAt(),
        'packed_by_uid' => $state->getPackedByUid(),
        'label_purchased_at' => $state->getLabelPurchasedAt(),
        'label_purchased_by_uid' => $state->getLabelPurchasedByUid(),
        'dispatched_at' => $state->getDispatchedAt(),
        'dispatched_by_uid' => $state->getDispatchedByUid(),
      ])
      ->condition('id', $state->getOrderLineId())
      ->execute();
  }

  private function toNullableInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    if (!is_numeric($value)) {
      return NULL;
    }

    return (int) $value;
  }

}
