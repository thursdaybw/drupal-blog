<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Contract;

use Drupal\marketplace_orders\Model\OrderLineWorkflowState;

/**
 * Port for loading and saving order-line workflow state.
 */
interface OrderLineWorkflowRepositoryInterface {

  /**
   * Loads workflow state by order line ID.
   */
  public function loadByOrderLineId(int $orderLineId): ?OrderLineWorkflowState;

  /**
   * Persists workflow state for an existing order line.
   */
  public function save(OrderLineWorkflowState $state): void;

}
