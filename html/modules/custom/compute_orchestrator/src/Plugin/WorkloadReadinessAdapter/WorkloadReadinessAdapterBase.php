<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\Core\Plugin\PluginBase;

abstract class WorkloadReadinessAdapterBase extends PluginBase implements WorkloadReadinessAdapterInterface {

  public function getStartupTimeoutSeconds(): int {
    return (int) ($this->pluginDefinition['startup_timeout'] ?? 900);
  }

  public function detectForwardProgress(array $previousProbeResults, array $currentProbeResults): bool {
    if (empty($previousProbeResults)) {
      return true;
    }

    $prevLogs = (string) ($previousProbeResults['logs']['stdout'] ?? '');
    $currLogs = (string) ($currentProbeResults['logs']['stdout'] ?? '');

    if (strlen($currLogs) > strlen($prevLogs)) {
      return true;
    }

    if ($currLogs !== $prevLogs) {
      return true;
    }

    return false;
  }

}
