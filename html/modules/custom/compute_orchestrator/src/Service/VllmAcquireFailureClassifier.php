<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\compute_orchestrator\Exception\AcquirePendingException;
use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;

/**
 * Classifies acquire/readiness failures into operational retry decisions.
 *
 * Vast and vLLM produce noisy, layered failures: a normal cold start can
 * include `/v1/models` connection refused, short polling-slice timeouts, and
 * transient SSH resets from diagnostic probes while the model process is still
 * warming. Keeping this classification outside the pool state machine prevents
 * one broad substring match from turning healthy warmup into bad-host fallback.
 */
final class VllmAcquireFailureClassifier {

  public const WARMUP_PENDING = 'warmup_pending';
  public const RUNTIME_LOST = 'runtime_lost';
  public const PROVIDER_TRANSIENT = 'provider_transient';
  public const FATAL_RUNTIME = 'fatal_runtime';

  /**
   * Classifies a thrown acquire/readiness failure.
   */
  public function classify(
    \Throwable $exception,
    bool $bootstrapCompleted,
    bool $workloadStartIssued,
  ): string {
    if ($exception instanceof AcquirePendingException) {
      return self::WARMUP_PENDING;
    }

    if ($exception instanceof WorkloadReadinessException) {
      return $this->classifyWorkloadReadinessException($exception);
    }

    $message = strtolower($exception->getMessage());

    // Positive warmup evidence wins over noisy probe text. The staging failure
    // that motivated this class contained `class=warmup` and healthy
    // process/GPU
    // probes, but also a transient SSH reset string from one probe. That must
    // remain pending on the same contract, not poison the host.
    if ($this->hasWarmupForwardProgressEvidence($message)) {
      return self::WARMUP_PENDING;
    }

    if (($bootstrapCompleted || $workloadStartIssued) && $this->isWarmupPendingMessage($message)) {
      return self::WARMUP_PENDING;
    }

    if ($this->isRuntimeLostMessage($message)) {
      return self::RUNTIME_LOST;
    }

    if ($this->isProviderTransientMessage($message)) {
      return self::PROVIDER_TRANSIENT;
    }

    return self::FATAL_RUNTIME;
  }

  /**
   * Returns TRUE when acquire should keep the same contract warming.
   */
  public function isWarmupPending(
    \Throwable $exception,
    bool $bootstrapCompleted,
    bool $workloadStartIssued,
  ): bool {
    return $this->classify($exception, $bootstrapCompleted, $workloadStartIssued) === self::WARMUP_PENDING;
  }

  /**
   * Returns TRUE when acquire should abandon this runtime attempt.
   */
  public function isRuntimeLost(
    \Throwable $exception,
    bool $bootstrapCompleted,
    bool $workloadStartIssued,
  ): bool {
    return $this->classify($exception, $bootstrapCompleted, $workloadStartIssued) === self::RUNTIME_LOST;
  }

  /**
   * Maps trusted workload-readiness failure classes to acquire outcomes.
   */
  private function classifyWorkloadReadinessException(WorkloadReadinessException $exception): string {
    return match ($exception->getFailureClass()) {
      FailureClass::RUNTIME_LOST => self::RUNTIME_LOST,
      FailureClass::WARMUP, FailureClass::UNKNOWN => self::WARMUP_PENDING,
      default => self::FATAL_RUNTIME,
    };
  }

  /**
   * Detects explicit warmup evidence from readiness adapter summaries.
   */
  private function hasWarmupForwardProgressEvidence(string $message): bool {
    if (str_contains($message, 'class=warmup')) {
      return TRUE;
    }

    return str_contains($message, 'workload warmup')
      || str_contains($message, 'forward progress detected');
  }

  /**
   * Detects ordinary cold-start / short polling-slice failures.
   */
  private function isWarmupPendingMessage(string $message): bool {
    foreach ([
      'readiness polling slice timed out',
      'bootstrap timeout',
      'absolute safety timeout',
      'stalled before ssh bootstrap',
      'connection refused',
      'failed to connect',
      'workload not ready',
      'warmup',
      'timed out',
      'operation timed out',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Detects stronger evidence that the runtime disappeared.
   */
  private function isRuntimeLostMessage(string $message): bool {
    foreach ([
      'runtime lost:',
      'instance entered failure state',
      'ssh never became reachable after workload container reported running',
      'connectivity loss, ssh probe unavailable',
      'provider reported stopped',
      'provider reported exited',
      'provider reported failed',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Detects provider throttling/outage that should not poison a host.
   */
  private function isProviderTransientMessage(string $message): bool {
    foreach ([
      'too many requests',
      'rate limit',
      'throttled',
      'provider readback unavailable',
      'vast api error response',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
