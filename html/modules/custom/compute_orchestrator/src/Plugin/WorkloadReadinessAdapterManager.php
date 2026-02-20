<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

final class WorkloadReadinessAdapterManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/WorkloadReadinessAdapter',
      $namespaces,
      $module_handler,
      'Drupal\\compute_orchestrator\\Plugin\\WorkloadReadinessAdapter\\WorkloadReadinessAdapterInterface',
      'Drupal\\compute_orchestrator\\Annotation\\WorkloadReadinessAdapter'
    );

    $this->alterInfo('compute_orchestrator_workload_readiness_adapter_info');
    $this->setCacheBackend($cache_backend, 'compute_orchestrator_workload_readiness_adapter_plugins');
  }

}
