<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Runs one Framesmith transcription task.
 */
final class FramesmithTranscriptionRunner {

  public function __construct(
    private readonly FramesmithTranscriptionTaskStoreInterface $taskStore,
  ) {}

  /**
   * Executes one Framesmith transcription task.
   *
   * This is currently a skeleton used to prove immediate detached kickoff
   * before wiring in real whisper acquisition and remote execution.
   *
   * @param string $taskId
   *   Task identifier.
   */
  public function run(string $taskId): void {
    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      throw new \RuntimeException('Unknown Framesmith transcription task: ' . $taskId);
    }

    $this->taskStore->transition(
      $taskId,
      'running',
      ['runner_started_at' => time()],
      'Stub runner started immediately without cron.',
    );

    usleep(250000);
    $this->taskStore->transition(
      $taskId,
      'acquiring_runtime',
      [],
      'Placeholder: compute_orchestrator whisper acquire will happen here.',
    );

    usleep(250000);
    $this->taskStore->transition(
      $taskId,
      'transcribing',
      [],
      'Placeholder: remote whisper execution will happen here.',
    );

    usleep(250000);
    $this->taskStore->transition(
      $taskId,
      'completed',
      [
        'result' => [
          'mode' => 'skeleton',
          'message' => 'Detached Drush runner executed successfully. Replace this stub with real compute_orchestrator-backed transcription.',
          'json' => [
            'text' => 'Framesmith detached transcription skeleton completed successfully.',
            'segments' => [],
          ],
          'json_url' => NULL,
          'completed_at' => time(),
        ],
      ],
      'Stub runner completed.',
    );
  }

}
