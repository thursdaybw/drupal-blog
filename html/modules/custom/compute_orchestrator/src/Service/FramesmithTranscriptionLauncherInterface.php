<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Launches detached Framesmith transcription work.
 */
interface FramesmithTranscriptionLauncherInterface {

  /**
   * Launches one transcription task asynchronously.
   *
   * @param string $taskId
   *   Task identifier.
   *
   * @return array<string,mixed>
   *   Launch metadata.
   */
  public function launch(string $taskId): array;

}
