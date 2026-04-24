<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\FramesmithTranscriptionTaskStore;
use Drush\Commands\DrushCommands;

/**
 * Runs one Framesmith transcription task.
 */
final class FramesmithTranscriptionCommand extends DrushCommands {

  public function __construct(
    private readonly FramesmithTranscriptionTaskStore $taskStore,
  ) {
    parent::__construct();
  }

  /**
   * Executes one Framesmith transcription task.
   *
   * This is a skeleton command used to prove immediate detached kickoff before
   * wiring in real whisper acquisition and remote execution.
   *
   * @command compute:framesmith-run-transcription
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
    $this->output()->writeln('Running Framesmith transcription task ' . $taskId . '.');

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

    $this->output()->writeln('Completed Framesmith transcription task ' . $taskId . '.');
  }

}
