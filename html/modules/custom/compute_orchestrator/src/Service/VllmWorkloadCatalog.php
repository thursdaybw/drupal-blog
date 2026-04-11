<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Provides supported workload definitions for the generic vLLM runtime.
 */
final class VllmWorkloadCatalog implements VllmWorkloadCatalogInterface {

  private const DEFAULT_GENERIC_IMAGE = 'thursdaybw/vllm-generic:2026-04-generic-node';

  /**
   * {@inheritdoc}
   */
  public function getDefaultGenericImage(): string {
    return self::DEFAULT_GENERIC_IMAGE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition(string $workload, ?string $modelOverride = NULL): array {
    $workloadMap = [
      'qwen-vl' => [
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        // Preserve the previously working Qwen context window until the
        // generic image is rebuilt with the same sane default. The current
        // image fallback of 4096 is too small for the real listing workflow.
        'max_model_len' => 16384,
      ],
      'whisper' => [
        'mode' => 'whisper',
        'model' => 'openai/whisper-large-v3-turbo',
        'gpu_ram_gte' => 16,
      ],
    ];

    if (!isset($workloadMap[$workload])) {
      throw new \InvalidArgumentException('Unsupported workload "' . $workload . '". Expected qwen-vl or whisper.');
    }

    $definition = $workloadMap[$workload];
    if ($modelOverride !== NULL && $modelOverride !== '') {
      $definition['model'] = $modelOverride;
    }

    return $definition;
  }

}
