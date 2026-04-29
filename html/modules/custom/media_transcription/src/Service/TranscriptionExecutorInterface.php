<?php

declare(strict_types=1);

namespace Drupal\media_transcription\Service;

/**
 * Executes one Framesmith transcription against the selected runtime mode.
 */
interface TranscriptionExecutorInterface {

  /**
   * Determines whether this executor requires a real runtime lease.
   */
  public function requiresRuntimeLease(): bool;

  /**
   * Transcribes one local audio file using the selected execution mode.
   *
   * @param array<string,mixed> $lease
   *   Lease metadata. Fake mode may ignore this.
   * @param string $localAudioPath
   *   Local audio file path or stream wrapper URI.
   * @param string $taskId
   *   Task identifier.
   *
   * @return array<string,mixed>
   *   Normalized transcription result payload.
   */
  public function transcribe(array $lease, string $localAudioPath, string $taskId): array;

}
