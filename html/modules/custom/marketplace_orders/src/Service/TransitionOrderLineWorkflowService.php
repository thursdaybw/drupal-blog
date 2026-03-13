<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\marketplace_orders\Contract\OrderLineWorkflowRepositoryInterface;
use Drupal\marketplace_orders\Model\OrderLineWorkflowAction;
use Drupal\marketplace_orders\Model\OrderLineWorkflowState;

/**
 * Applies monotonic and idempotent workflow transitions to order lines.
 */
final class TransitionOrderLineWorkflowService {

  /**
   * @var array<string, int>
   */
  private const STATUS_RANK = [
    'new' => 0,
    'picked' => 1,
    'packed' => 2,
    'label_purchased' => 3,
    'dispatched' => 4,
  ];

  /**
   * @var array<string, string>
   */
  private const TARGET_STATUS_BY_ACTION = [
    OrderLineWorkflowAction::PICKED => 'picked',
    OrderLineWorkflowAction::PACKED => 'packed',
    OrderLineWorkflowAction::LABEL_PURCHASED => 'label_purchased',
    OrderLineWorkflowAction::DISPATCHED => 'dispatched',
  ];

  public function __construct(
    private readonly OrderLineWorkflowRepositoryInterface $workflowRepository,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Advances one order line workflow state.
   *
   * @throws \InvalidArgumentException
   *   Thrown when action is unknown, line is missing, or transition is skipped.
   */
  public function transition(int $orderLineId, string $action, ?int $actorUid = NULL): OrderLineWorkflowState {
    $normalizedAction = $this->normalizeAction($action);
    $targetStatus = self::TARGET_STATUS_BY_ACTION[$normalizedAction];

    $currentState = $this->workflowRepository->loadByOrderLineId($orderLineId);
    if ($currentState === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown order line ID %d.',
        $orderLineId
      ));
    }

    $currentRank = $this->resolveStatusRank($currentState->getWarehouseStatus());
    $targetRank = self::STATUS_RANK[$targetStatus];

    if ($currentRank >= $targetRank) {
      return $currentState;
    }

    if (($currentRank + 1) !== $targetRank) {
      throw new \InvalidArgumentException(sprintf(
        'Invalid transition: current status "%s", attempted action "%s".',
        $currentState->getWarehouseStatus(),
        $normalizedAction
      ));
    }

    $nextState = $this->buildTransitionedState(
      $currentState,
      $targetStatus,
      $actorUid
    );

    $this->workflowRepository->save($nextState);
    return $nextState;
  }

  private function normalizeAction(string $action): string {
    $normalized = trim(strtolower($action));
    if (!in_array($normalized, OrderLineWorkflowAction::all(), TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown workflow action "%s".',
        $action
      ));
    }

    return $normalized;
  }

  private function resolveStatusRank(string $status): int {
    $normalized = trim(strtolower($status));
    if (isset(self::STATUS_RANK[$normalized])) {
      return self::STATUS_RANK[$normalized];
    }

    return self::STATUS_RANK['new'];
  }

  private function buildTransitionedState(OrderLineWorkflowState $currentState, string $targetStatus, ?int $actorUid): OrderLineWorkflowState {
    $timestamp = $this->time->getCurrentTime();

    $pickedAt = $currentState->getPickedAt();
    $pickedByUid = $currentState->getPickedByUid();
    $packedAt = $currentState->getPackedAt();
    $packedByUid = $currentState->getPackedByUid();
    $labelPurchasedAt = $currentState->getLabelPurchasedAt();
    $labelPurchasedByUid = $currentState->getLabelPurchasedByUid();
    $dispatchedAt = $currentState->getDispatchedAt();
    $dispatchedByUid = $currentState->getDispatchedByUid();

    if ($targetStatus === 'picked') {
      $pickedAt = $pickedAt ?? $timestamp;
      $pickedByUid = $pickedByUid ?? $actorUid;
    }

    if ($targetStatus === 'packed') {
      $packedAt = $packedAt ?? $timestamp;
      $packedByUid = $packedByUid ?? $actorUid;
    }

    if ($targetStatus === 'label_purchased') {
      $labelPurchasedAt = $labelPurchasedAt ?? $timestamp;
      $labelPurchasedByUid = $labelPurchasedByUid ?? $actorUid;
    }

    if ($targetStatus === 'dispatched') {
      $dispatchedAt = $dispatchedAt ?? $timestamp;
      $dispatchedByUid = $dispatchedByUid ?? $actorUid;
    }

    return new OrderLineWorkflowState(
      orderLineId: $currentState->getOrderLineId(),
      warehouseStatus: $targetStatus,
      pickedAt: $pickedAt,
      pickedByUid: $pickedByUid,
      packedAt: $packedAt,
      packedByUid: $packedByUid,
      labelPurchasedAt: $labelPurchasedAt,
      labelPurchasedByUid: $labelPurchasedByUid,
      dispatchedAt: $dispatchedAt,
      dispatchedByUid: $dispatchedByUid,
    );
  }

}
