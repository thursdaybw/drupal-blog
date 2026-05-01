<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Provides the pool operations needed by Framesmith runtime leasing.
 */
interface VllmPoolLeaseBrokerInterface {

  /**
   * Acquires a pooled runtime.
   *
   * @return array<string,mixed>
   *   Acquired pool record.
   */
  public function acquire(
    string $workload,
    ?string $modelOverride = NULL,
    bool $allowFresh = TRUE,
    ?int $bootstrapTimeoutSeconds = NULL,
    ?int $workloadTimeoutSeconds = NULL,
  ): array;

  /**
   * Releases a pooled runtime.
   *
   * @return array<string,mixed>
   *   Updated pool record.
   */
  public function release(string $contractId): array;

}
