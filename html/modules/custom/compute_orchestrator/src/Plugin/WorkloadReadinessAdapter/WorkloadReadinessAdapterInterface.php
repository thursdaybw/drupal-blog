<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\Component\Plugin\PluginInspectionInterface;

interface WorkloadReadinessAdapterInterface extends PluginInspectionInterface {

  public function getStartupTimeoutSeconds(): int;

  /**
   * @return array<string, array{command:string, timeout:int}>
   */
  public function getReadinessProbeCommands(): array;

  public function isReadyFromProbeResults(array $results): bool;

  public function classifyFailure(array $probeResults): string;

}
