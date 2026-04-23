<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapter;

use Drupal\compute_orchestrator\Service\Workload\FailureClass;

/**
 * Readiness adapter for vLLM-based model servers.
 *
 * @WorkloadReadinessAdapter(
 *   id = "vllm",
 *   label = @Translation("vLLM Readiness Adapter"),
 *   startup_timeout = 900
 * )
 */
final class VllmReadinessAdapter extends WorkloadReadinessAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function getReadinessProbeCommands(): array {
    return [
      'executor_echo' => [
        'command' => "printf '__PROBE_OK__\\n'",
        'timeout' => 15,
      ],
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
        'command' => 'cat /tmp/vllm.log 2>/dev/null || cat /opt/vllm/run/server.log 2>/dev/null || true',
        'timeout' => 10,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isReadyFromProbeResults(array $results): bool {
    return (bool) (
      (($results['models_8000']['ok'] ?? FALSE) === TRUE) ||
      (($results['models_8080']['ok'] ?? FALSE) === TRUE)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function classifyFailure(array $probeResults): string {
    if (($probeResults['executor_echo']['ok'] ?? FALSE) !== TRUE) {
      return FailureClass::UNKNOWN;
    }

    $logs = strtolower((string) ($probeResults['logs']['stdout'] ?? ''));
    $processes = strtolower((string) ($probeResults['processes']['stdout'] ?? ''));

    $models8000 = (bool) ($probeResults['models_8000']['ok'] ?? FALSE);
    $models8080 = (bool) ($probeResults['models_8080']['ok'] ?? FALSE);

    $anyApiUp = $models8000 || $models8080;
    $hasProcess = trim($processes) !== '';

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

    $driver_runtime_imcompatibility_indicators = [
      // Driver/runtime incompatibility indicators.
      'cannot find -lcuda',
      'engine core initialization failed',
      'runtimeerror: engine core initialization failed',
      'inductorerror',
    ];

    foreach ($driver_runtime_imcompatibility_indicators as $marker) {
      if (str_contains($logs, $marker)) {
        return FailureClass::WORKLOAD_INCOMPATIBLE;
      }
    }

    // Logical workload failures (config, bad args, etc)
    $logical_workload_failures = [
      'failed core proc',
      'returned non-zero exit status',
      'traceback',
      'runtimeerror',
    ];
    foreach ($logical_workload_failures as $marker) {
      if (str_contains($logs, $marker)) {
        return FailureClass::WORKLOAD_FATAL;
      }
    }

    if (!$anyApiUp && !$hasProcess) {
      // Cold starts can transiently report no API and no matched process while
      // the runtime is still initializing. Treat this as warmup unless an
      // explicit fatal marker was already detected above.
      return FailureClass::WARMUP;
    }

    if (trim($processes) !== '') {
      return FailureClass::WARMUP;
    }

    return FailureClass::UNKNOWN;
  }

  /**
   * {@inheritdoc}
   */
  public function detectForwardProgress(array $previousProbeResults, array $currentProbeResults): bool {
    if (empty($previousProbeResults)) {
      return TRUE;
    }

    $wasReady = (bool) (($previousProbeResults['models_8000']['ok'] ?? FALSE) || ($previousProbeResults['models_8080']['ok'] ?? FALSE));
    $isReady = (bool) (($currentProbeResults['models_8000']['ok'] ?? FALSE) || ($currentProbeResults['models_8080']['ok'] ?? FALSE));
    if (!$wasReady && $isReady) {
      return TRUE;
    }

    $prevLogs = (string) ($previousProbeResults['logs']['stdout'] ?? '');
    $currLogs = (string) ($currentProbeResults['logs']['stdout'] ?? '');

    if (strlen($currLogs) > strlen($prevLogs)) {
      return TRUE;
    }

    return FALSE;
  }

}
