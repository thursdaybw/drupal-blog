<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Provides Framesmith-specific access to pooled transcription runtimes.
 */
interface FramesmithRuntimeLeaseManagerInterface {

  /**
   * Acquires a Whisper runtime for transcription work.
   *
   * @return array<string,mixed>
   *   Lease details derived from the pooled runtime record.
   */
  public function acquireWhisperRuntime(): array;

  /**
   * Releases a previously acquired runtime lease.
   *
   * @param string $contractId
   *   Pooled runtime contract identifier.
   *
   * @return array<string,mixed>
   *   Updated pool record.
   */
  public function releaseRuntime(string $contractId): array;

}
