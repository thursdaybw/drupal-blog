<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\FramesmithTranscriptionRunner;
use Drush\Commands\DrushCommands;

/**
 * Runs one Framesmith transcription task.
 */
final class FramesmithTranscriptionCommand extends DrushCommands {

  public function __construct(
    private readonly FramesmithTranscriptionRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * Executes one Framesmith transcription task.
   *
   * @command compute:framesmith-run-transcription
   */
  public function run(string $taskId): void {
    $this->output()->writeln('Running Framesmith transcription task ' . $taskId . '.');
    $this->runner->run($taskId);
    $this->output()->writeln('Completed Framesmith transcription task ' . $taskId . '.');
  }

}
