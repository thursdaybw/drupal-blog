<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Defines the Framesmith backend client for compute runtimes.
 *
 * This is the extraction-facing seam. The current implementation may call
 * Drupal PHP services directly, while a later implementation can call the
 * remote compute runtime lease API over HTTP without changing the Framesmith
 * transcription runner contract.
 */
interface FramesmithComputeRuntimeClientInterface {

  /**
   * Acquires a Whisper runtime for transcription work.
   *
   * @return array<string,mixed>
   *   Lease details in the Framesmith backend task shape.
   */
  public function acquireWhisperRuntime(): array;

  /**
   * Releases a previously acquired runtime lease.
   *
   * @param string $contractId
   *   Pooled runtime contract identifier.
   * @param string|null $leaseToken
   *   Backend-owned lease token required by remote compute clients.
   *
   * @return array<string,mixed>
   *   Updated lease/runtime details.
   */
  public function releaseRuntime(string $contractId, ?string $leaseToken = NULL): array;

}
