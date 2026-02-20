<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Exception;

final class WorkloadReadinessException extends \RuntimeException {

  public function __construct(private readonly string $failureClass, string $message) {
    parent::__construct($message);
  }

  public function getFailureClass(): string {
    return $this->failureClass;
  }

}
