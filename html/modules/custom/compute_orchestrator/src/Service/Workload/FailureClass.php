<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service\Workload;

/**
 * Classification of workload startup failures.
 *
 * These values drive blacklist scope and retry behaviour.
 *
 * IMPORTANT:
 * Classification does not describe *who is at fault*.
 * It describes *how the orchestrator should react*.
 */
final class FailureClass {

  /**
   * Host or container runtime is fundamentally broken.
   *
   * Examples:
   * - SSH key injection failure
   * - OCI runtime create failed
   * - GPU not available at container level
   *
   * Reaction:
   * - Global blacklist (all workloads)
   */
  public const INFRA_FATAL = 'infra_fatal';

  /**
   * Workload configuration is invalid regardless of host.
   *
   * Examples:
   * - Invalid model name
   * - Bad CLI arguments
   * - Missing required env variables
   *
   * Reaction:
   * - Do NOT blacklist host
   * - Fail fast and surface configuration error
   */
  public const WORKLOAD_FATAL = 'workload_fatal';

  /**
   * Host is incompatible with this specific workload fingerprint.
   *
   * Examples:
   * - Triton JIT fails due to driver/runtime mismatch
   * - CUDA library link errors for this model stack
   *
   * Host may work for other workloads.
   *
   * Reaction:
   * - Workload-scoped blacklist only
   * - Do NOT global blacklist
   */
  public const WORKLOAD_INCOMPATIBLE = 'workload_incompatible';

  /**
   * Workload is still warming up.
   *
   * Examples:
   * - Model loading
   * - CUDA graph capture
   *
   * Reaction:
   * - Continue polling within timeout window
   */
  public const WARMUP = 'warmup';

  /**
   * Unable to classify.
   *
   * Reaction:
   * - Continue polling unless timeout exceeded
   */
  public const UNKNOWN = 'unknown';

}
