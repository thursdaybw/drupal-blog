<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Psr\Log\LoggerInterface;

/**
 * Launches detached Framesmith transcription runners.
 */
final class FramesmithTranscriptionLauncher {

  public function __construct(
    private readonly FramesmithTranscriptionTaskStoreInterface $taskStore,
    private readonly LoggerInterface $logger,
    private readonly string $appRoot,
  ) {}

  /**
   * Launches the detached runner for one task.
   *
   * @return array<string,mixed>
   *   Launch metadata.
   */
  public function launch(string $taskId): array {
    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      throw new \RuntimeException('Unknown Framesmith transcription task: ' . $taskId);
    }
    if (trim((string) ($task['local_audio_path'] ?? '')) === '') {
      throw new \RuntimeException('Cannot launch task without uploaded audio: ' . $taskId);
    }

    $logDirectory = dirname($this->appRoot) . '/private/framesmith-transcription-logs';
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0770, TRUE) && !is_dir($logDirectory)) {
      throw new \RuntimeException('Failed to create Framesmith log directory.');
    }

    $logPath = $logDirectory . '/' . $taskId . '.log';
    $drushBinary = $this->resolveDrushBinary();
    $command = sprintf(
      'setsid %s compute:framesmith-run-transcription %s >> %s 2>&1 < /dev/null & echo $!',
      escapeshellarg($drushBinary),
      escapeshellarg($taskId),
      escapeshellarg($logPath),
    );

    $process = proc_open(
      ['/bin/bash', '-lc', $command],
      [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ],
      $pipes,
      $this->appRoot,
    );

    if (!is_resource($process)) {
      throw new \RuntimeException('Failed to start detached Framesmith runner process.');
    }

    fclose($pipes[0]);
    $stdout = trim((string) stream_get_contents($pipes[1]));
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $stdout === '' || !ctype_digit($stdout)) {
      $message = 'Failed to launch detached Framesmith runner.';
      $this->taskStore->fail($taskId, $message, [
        'launch' => [
          'command' => $drushBinary . ' compute:framesmith-run-transcription ' . $taskId,
          'log_path' => $logPath,
          'stdout' => $stdout,
          'stderr' => $stderr,
          'exit_code' => $exitCode,
          'launched' => FALSE,
        ],
      ]);
      throw new \RuntimeException($message . ' stderr=' . $stderr);
    }

    $launch = [
      'command' => $drushBinary . ' compute:framesmith-run-transcription ' . $taskId,
      'log_path' => $logPath,
      'pid' => (int) $stdout,
      'launched_at' => time(),
      'launched' => TRUE,
    ];

    $this->taskStore->markLaunch($taskId, $launch);
    $this->logger->notice(
      'Framesmith transcription runner launched for task {task_id} pid={pid}.',
      [
        'task_id' => $taskId,
        'pid' => (int) $stdout,
      ],
    );

    return $launch;
  }

  /**
   * Resolves the drush executable path.
   */
  private function resolveDrushBinary(): string {
    $candidates = [
      dirname($this->appRoot) . '/vendor/bin/drush',
      $this->appRoot . '/vendor/bin/drush',
      'drush',
    ];

    foreach ($candidates as $candidate) {
      if ($candidate === 'drush') {
        return $candidate;
      }
      if (is_file($candidate) && is_executable($candidate)) {
        return $candidate;
      }
    }

    return 'drush';
  }

}
