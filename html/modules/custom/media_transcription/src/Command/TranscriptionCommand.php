<?php

declare(strict_types=1);

namespace Drupal\media_transcription\Command;

use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs one Framesmith transcription task.
 */
final class TranscriptionCommand extends DrushCommands {

  public function __construct(
    private readonly ContainerInterface $container,
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

    try {
      $this->container->get('media_transcription.transcription_runner')->run($taskId);
    }
    catch (\Throwable $exception) {
      $this->container->get('logger.channel.media_transcription')->error(
        'Framesmith transcription task {task_id} failed: {message}',
        [
          'task_id' => $taskId,
          'message' => $exception->getMessage(),
          'exception' => $exception,
        ],
      );
      throw $exception;
    }

    $this->output()->writeln('Completed Framesmith transcription task ' . $taskId . '.');
  }

}
