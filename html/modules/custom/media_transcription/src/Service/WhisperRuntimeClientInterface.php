<?php

declare(strict_types=1);

namespace Drupal\media_transcription\Service;

/**
 * Defines a backend client for Whisper compute runtimes.
 *
 * This is the extraction-facing seam. The current implementation may call
 * Drupal PHP services directly, while a later implementation can call the
 * remote compute runtime lease API over HTTP without changing the transcription
 * runner contract.
 */
interface WhisperRuntimeClientInterface {

  /**
   * Acquires a Whisper runtime for transcription work.
   *
   * @return array<string,mixed>
   *   Lease details in the transcription backend task shape.
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
