<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\compute_orchestrator\Service\Workload\FailureClass;

/**
 * @WorkloadReadinessAdapter(
 *   id = "vllm",
 *   label = @Translation("vLLM Readiness Adapter"),
 *   startup_timeout = 900
 * )
 */
final class VllmReadinessAdapter extends WorkloadReadinessAdapterBase {

  public function getReadinessProbeCommands(): array {
    return [
      'models_8000' => [
        'command' => 'curl -fsS http://127.0.0.1:8000/v1/models',
        'timeout' => 10,
      ],
      'models_8080' => [
        'command' => 'curl -fsS http://127.0.0.1:8080/v1/models',
        'timeout' => 10,
      ],
      'processes' => [
        'command' => "ps -ef | grep -E 'vllm|api_server|openai' | grep -v grep || true",
        'timeout' => 10,
      ],
      'gpu' => [
        'command' => 'nvidia-smi || true',
        'timeout' => 10,
      ],
      'logs' => [
        'command' => 'tail -n 80 /tmp/vllm.log 2>/dev/null || echo "(missing /tmp/vllm.log)"',
        'timeout' => 10,
      ],
    ];
  }

  public function isReadyFromProbeResults(array $results): bool {
    return (bool) (
      (($results['models_8000']['ok'] ?? false) === true) ||
      (($results['models_8080']['ok'] ?? false) === true)
    );
  }

  public function classifyFailure(array $probeResults): string {
    $logs = strtolower((string) ($probeResults['logs']['stdout'] ?? ''));
    $processes = strtolower((string) ($probeResults['processes']['stdout'] ?? ''));

    foreach ([
      'unsupported display driver / cuda driver combination',
      'cudagetdevicecount',
      'oci runtime create failed',
      'gpu error, unable to start instance',
      'failed to create task for container',
      'error response from daemon',
    ] as $marker) {
      if (str_contains($logs, $marker)) {
        return FailureClass::INFRA_FATAL;
      }
    }

    foreach ([
      'engine core initialization failed',
      'traceback',
      'runtimeerror',
    ] as $marker) {
      if (str_contains($logs, $marker)) {
        return FailureClass::WORKLOAD_FATAL;
      }
    }

    if (trim($processes) !== '') {
      return FailureClass::WARMUP;
    }

    return FailureClass::UNKNOWN;
  }

}
