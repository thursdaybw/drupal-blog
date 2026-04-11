<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Controls start/stop state transitions for existing Vast instances.
 */
interface VastInstanceLifecycleClientInterface {

  /**
   * Requests that a stopped Vast instance transitions to running.
   */
  public function startInstance(string $instanceId): array;

  /**
   * Requests that a running or scheduling Vast instance transitions to stopped.
   */
  public function stopInstance(string $instanceId): array;

}
