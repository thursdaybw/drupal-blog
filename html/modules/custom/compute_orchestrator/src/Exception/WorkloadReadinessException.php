<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Exception;

/**
 * Exception thrown when a workload does not become ready within the budget.
 */
final class WorkloadReadinessException extends \RuntimeException {

  public function __construct(private readonly string $failureClass, string $message) {
    parent::__construct($message);
  }

  /**
   * Returns a coarse failure classification for operator/automation handling.
   */
  public function getFailureClass(): string {
    return $this->failureClass;
  }

}
