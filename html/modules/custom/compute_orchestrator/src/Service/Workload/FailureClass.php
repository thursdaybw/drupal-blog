<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service\Workload;

final class FailureClass {

  public const INFRA_FATAL = 'infra_fatal';
  public const WORKLOAD_FATAL = 'workload_fatal';
  public const WARMUP = 'warmup';
  public const UNKNOWN = 'unknown';

}
