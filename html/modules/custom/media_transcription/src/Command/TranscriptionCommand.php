<?php

declare(strict_types=1);

namespace Drupal\media_transcription\Command;

use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs one transcription task.
 */
final class TranscriptionCommand extends DrushCommands {

  public function __construct(
    private readonly ContainerInterface $container,
  ) {
    parent::__construct();
  }

  /**
   * Executes one transcription task.
   *
   * @command media-transcription:run-task
   */
  public function run(string $taskId): void {
    $this->output()->writeln('Running transcription task ' . $taskId . '.');

    try {
      $this->container->get('media_transcription.transcription_runner')->run($taskId);
    }
    catch (\Throwable $exception) {
      $this->container->get('logger.channel.media_transcription')->error(
        'transcription task {task_id} failed: {message}',
        [
          'task_id' => $taskId,
          'message' => $exception->getMessage(),
          'exception' => $exception,
        ],
      );
      throw $exception;
    }

    $this->output()->writeln('Completed transcription task ' . $taskId . '.');
  }

}
