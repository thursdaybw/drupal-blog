<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Immutable workflow state for one order line.
 */
final class OrderLineWorkflowState {

  public function __construct(
    private readonly int $orderLineId,
    private readonly string $warehouseStatus,
    private readonly ?int $pickedAt,
    private readonly ?int $pickedByUid,
    private readonly ?int $packedAt,
    private readonly ?int $packedByUid,
    private readonly ?int $labelPurchasedAt,
    private readonly ?int $labelPurchasedByUid,
    private readonly ?int $dispatchedAt,
    private readonly ?int $dispatchedByUid,
  ) {}

  public function getOrderLineId(): int { return $this->orderLineId; }
  public function getWarehouseStatus(): string { return $this->warehouseStatus; }
  public function getPickedAt(): ?int { return $this->pickedAt; }
  public function getPickedByUid(): ?int { return $this->pickedByUid; }
  public function getPackedAt(): ?int { return $this->packedAt; }
  public function getPackedByUid(): ?int { return $this->packedByUid; }
  public function getLabelPurchasedAt(): ?int { return $this->labelPurchasedAt; }
  public function getLabelPurchasedByUid(): ?int { return $this->labelPurchasedByUid; }
  public function getDispatchedAt(): ?int { return $this->dispatchedAt; }
  public function getDispatchedByUid(): ?int { return $this->dispatchedByUid; }

}
