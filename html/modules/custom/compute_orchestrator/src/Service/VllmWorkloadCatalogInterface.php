<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Defines the supported generic vLLM workload catalog.
 */
interface VllmWorkloadCatalogInterface {

  /**
   * Returns the default generic image tag used for pooled runtimes.
   */
  public function getDefaultGenericImage(): string;

  /**
   * Returns a normalized workload definition.
   *
   * @return array<string, int|string>
   *   Workload definition keyed by mode, model, gpu_ram_gte, and optionally
   *   max_model_len.
   */
  public function getDefinition(string $workload, ?string $modelOverride = NULL): array;

}
