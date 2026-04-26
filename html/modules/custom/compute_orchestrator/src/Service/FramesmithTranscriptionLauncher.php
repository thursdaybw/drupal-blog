<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Launches detached Framesmith transcription runners.
 */
final class FramesmithTranscriptionLauncher implements FramesmithTranscriptionLauncherInterface {

  /**
   * Logger channel for transcription launcher events.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly FramesmithTranscriptionTaskStoreInterface $taskStore,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly string $appRoot,
    private readonly FileSystemInterface $fileSystem,
  ) {
    $this->logger = $loggerFactory->get('compute_orchestrator');
  }

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

    $drushBinary = $this->resolveDrushBinary();
    $outputDirectory = 'temporary://framesmith-transcription/' . $taskId . '/runner-output';
    if (!$this->fileSystem->prepareDirectory($outputDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Failed to prepare Framesmith runner output directory.');
    }
    $outputRealDirectory = $this->fileSystem->realpath($outputDirectory);
    if ($outputRealDirectory === FALSE || !is_dir($outputRealDirectory)) {
      throw new \RuntimeException('Failed to resolve Framesmith runner output directory.');
    }

    $stdoutPath = $outputRealDirectory . '/stdout.log';
    $stderrPath = $outputRealDirectory . '/stderr.log';
    if (file_put_contents($stdoutPath, '') === FALSE || file_put_contents($stderrPath, '') === FALSE) {
      throw new \RuntimeException('Failed to initialize Framesmith runner output files.');
    }

    $command = sprintf(
      'setsid %s compute:framesmith-run-transcription %s > %s 2> %s < /dev/null & echo $!',
      escapeshellarg($drushBinary),
      escapeshellarg($taskId),
      escapeshellarg($stdoutPath),
      escapeshellarg($stderrPath),
    );

    $this->taskStore->merge($taskId, [
      'runner_output' => [
        'stdout_path' => $stdoutPath,
        'stderr_path' => $stderrPath,
      ],
      'launch_debug' => $this->buildLaunchDebugSnapshot(
        'prepared',
        $drushBinary,
        $command,
        $outputRealDirectory,
        $stdoutPath,
        $stderrPath,
      ),
    ]);

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
      $this->taskStore->merge($taskId, [
        'launch_debug' => $this->buildLaunchDebugSnapshot(
          'proc_open_failed',
          $drushBinary,
          $command,
          $outputRealDirectory,
          $stdoutPath,
          $stderrPath,
        ),
      ]);
      throw new \RuntimeException('Failed to start detached Framesmith runner process.');
    }

    fclose($pipes[0]);
    $stdout = trim((string) stream_get_contents($pipes[1]));
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $debug = $this->buildLaunchDebugSnapshot(
      'proc_closed',
      $drushBinary,
      $command,
      $outputRealDirectory,
      $stdoutPath,
      $stderrPath,
      $stdout,
      $stderr,
      $exitCode,
    );
    $this->taskStore->merge($taskId, ['launch_debug' => $debug]);

    if ($exitCode !== 0 || $stdout === '' || !ctype_digit($stdout)) {
      $message = 'Failed to launch detached Framesmith runner.';
      $this->taskStore->fail($taskId, $message, [
        'launch' => [
          'command' => $drushBinary . ' compute:framesmith-run-transcription ' . $taskId,
          'stdout' => $stdout,
          'stderr' => $stderr,
          'exit_code' => $exitCode,
          'launched' => FALSE,
          'stdout_path' => $stdoutPath,
          'stderr_path' => $stderrPath,
        ],
        'runner_output' => [
          'stdout_path' => $stdoutPath,
          'stderr_path' => $stderrPath,
        ],
        'launch_debug' => $debug,
      ]);
      throw new \RuntimeException($message . ' stderr=' . $stderr);
    }

    $launch = [
      'command' => $drushBinary . ' compute:framesmith-run-transcription ' . $taskId,
      'pid' => (int) $stdout,
      'launched_at' => time(),
      'launched' => TRUE,
      'stdout_path' => $stdoutPath,
      'stderr_path' => $stderrPath,
    ];

    $this->taskStore->markLaunch($taskId, $launch);
    $this->taskStore->merge($taskId, [
      'runner_output' => [
        'stdout_path' => $stdoutPath,
        'stderr_path' => $stderrPath,
      ],
      'launch_debug' => $debug,
    ]);
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
   * Builds a task-visible launch debug snapshot.
   *
   * @return array<string,mixed>
   *   Debug data for the current launcher seam.
   */
  private function buildLaunchDebugSnapshot(
    string $stage,
    string $drushBinary,
    string $command,
    string $outputDirectory,
    string $stdoutPath,
    string $stderrPath,
    string $procStdout = '',
    string $procStderr = '',
    int $procExitCode = 0,
  ): array {
    return [
      'stage' => $stage,
      'app_root' => $this->appRoot,
      'drush_binary' => $drushBinary,
      'command' => $command,
      'output_directory' => $outputDirectory,
      'output_directory_exists' => is_dir($outputDirectory),
      'stdout_path' => $stdoutPath,
      'stderr_path' => $stderrPath,
      'stdout_exists' => is_file($stdoutPath),
      'stderr_exists' => is_file($stderrPath),
      'stdout_size' => is_file($stdoutPath) ? (filesize($stdoutPath) ?: 0) : 0,
      'stderr_size' => is_file($stderrPath) ? (filesize($stderrPath) ?: 0) : 0,
      'proc_stdout' => $procStdout,
      'proc_stderr' => $procStderr,
      'proc_exit_code' => $procExitCode,
      'returned_pid_raw' => $procStdout,
      'returned_pid' => ctype_digit($procStdout) ? (int) $procStdout : 0,
      'captured_at' => time(),
    ];
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
