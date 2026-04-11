<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for workload readiness adapters.
 */
abstract class WorkloadReadinessAdapterBase extends PluginBase implements WorkloadReadinessAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function getStartupTimeoutSeconds(): int {
    return (int) ($this->pluginDefinition['startup_timeout'] ?? 900);
  }

  /**
   * {@inheritdoc}
   */
  public function detectForwardProgress(array $previousProbeResults, array $currentProbeResults): bool {
    if (empty($previousProbeResults)) {
      return TRUE;
    }

    $prevLogs = (string) ($previousProbeResults['logs']['stdout'] ?? '');
    $currLogs = (string) ($currentProbeResults['logs']['stdout'] ?? '');

    if (strlen($currLogs) > strlen($prevLogs)) {
      return TRUE;
    }

    if ($currLogs !== $prevLogs) {
      return TRUE;
    }

    return FALSE;
  }

}
