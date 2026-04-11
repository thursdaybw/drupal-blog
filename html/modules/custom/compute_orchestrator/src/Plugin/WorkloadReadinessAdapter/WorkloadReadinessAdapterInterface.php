<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for workload readiness adapters.
 */
interface WorkloadReadinessAdapterInterface extends PluginInspectionInterface {

  /**
   * Returns the maximum startup window (seconds) before the workload is failed.
   */
  public function getStartupTimeoutSeconds(): int;

  /**
   * Returns probe commands keyed by name.
   *
   * @return array<string, array{command:string, timeout:int}>
   *   Probe command definitions.
   */
  public function getReadinessProbeCommands(): array;

  /**
   * Determines readiness from probe results.
   */
  public function isReadyFromProbeResults(array $results): bool;

  /**
   * Classifies a failure for operator/automation handling.
   */
  public function classifyFailure(array $probeResults): string;

  /**
   * Detects forward progress between probe snapshots to avoid false stalls.
   */
  public function detectForwardProgress(array $previousProbeResults, array $currentProbeResults): bool;

}
