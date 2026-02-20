<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\Core\Plugin\PluginBase;

abstract class WorkloadReadinessAdapterBase extends PluginBase implements WorkloadReadinessAdapterInterface {

  public function getStartupTimeoutSeconds(): int {
    return (int) ($this->pluginDefinition['startup_timeout'] ?? 900);
  }

}
