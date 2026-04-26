<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores Framesmith transcription task state in Drupal state.
 *
 * This is intentionally lightweight smoke/dev storage for the first Framesmith
 * integration slice. It is not intended to be the long-term durable task
 * repository once task volume, concurrent writes, or retention requirements
 * grow beyond local development and controlled smoke testing.
 */
final class FramesmithTranscriptionTaskStore implements FramesmithTranscriptionTaskStoreInterface {

  private const STATE_KEY = 'compute_orchestrator.framesmith_transcription.tasks';

  public function __construct(
    private readonly StateInterface $state,
    private readonly UuidInterface $uuid,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Creates a new task.
   *
   * @param array<string,mixed> $values
   *   Optional seed values.
   *
   * @return array<string,mixed>
   *   Saved task record.
   */
  public function create(array $values = []): array {
    $taskId = $this->uuid->generate();
    $now = time();
    $task = [
      'task_id' => $taskId,
      'status' => 'created',
      'video_id' => (string) ($values['video_id'] ?? ''),
      'created_at' => $now,
      'updated_at' => $now,
      'last_error' => '',
      'local_audio_path' => '',
      'launch_ready' => FALSE,
      'launch' => [],
      'runner_output' => [
        'stdout_path' => '',
        'stderr_path' => '',
        'stdout_tail' => '',
        'stderr_tail' => '',
      ],
      'launch_debug' => [
        'stage' => '',
        'app_root' => '',
        'drush_binary' => '',
        'command' => '',
        'output_directory' => '',
        'output_directory_exists' => FALSE,
        'stdout_path' => '',
        'stderr_path' => '',
        'stdout_exists' => FALSE,
        'stderr_exists' => FALSE,
        'stdout_size' => 0,
        'stderr_size' => 0,
        'proc_stdout' => '',
        'proc_stderr' => '',
        'proc_exit_code' => 0,
        'returned_pid_raw' => '',
        'returned_pid' => 0,
        'captured_at' => 0,
      ],
      'debug_events' => [],
      'runtime_contract_id' => '',
      'runtime_lease_snapshot' => [],
      'runtime_release_snapshot' => [],
      'result' => NULL,
      'history' => [
        [
          'status' => 'created',
          'timestamp' => $now,
          'message' => 'Task created.',
        ],
      ],
    ];

    return $this->save($task);
  }

  /**
   * Returns all tasks.
   *
   * @return array<string,array<string,mixed>>
   *   Task records keyed by task ID.
   */
  public function all(): array {
    $tasks = $this->state->get(self::STATE_KEY, []);
    return is_array($tasks) ? $tasks : [];
  }

  /**
   * Returns one task or NULL.
   *
   * @param string $taskId
   *   Task identifier.
   *
   * @return array<string,mixed>|null
   *   Task record or NULL.
   */
  public function get(string $taskId): ?array {
    $task = $this->all()[$taskId] ?? NULL;
    return is_array($task) ? $task : NULL;
  }

  /**
   * Merges arbitrary values into a task.
   *
   * @param string $taskId
   *   Task identifier.
   * @param array<string,mixed> $values
   *   Values to merge.
   *
   * @return array<string,mixed>
   *   Updated task record.
   */
  public function merge(string $taskId, array $values): array {
    $task = array_replace($this->requireTask($taskId), $values);
    $task['updated_at'] = time();
    return $this->save($task);
  }

  /**
   * Records a status transition.
   *
   * @param string $taskId
   *   Task identifier.
   * @param string $status
   *   New task status.
   * @param array<string,mixed> $extra
   *   Additional values to merge.
   * @param string $message
   *   History message for the transition.
   *
   * @return array<string,mixed>
   *   Updated task record.
   */
  public function transition(string $taskId, string $status, array $extra = [], string $message = ''): array {
    $task = array_replace($this->requireTask($taskId), $extra);
    $now = time();
    $task['status'] = $status;
    $task['updated_at'] = $now;
    $history = $task['history'] ?? [];
    if (!is_array($history)) {
      $history = [];
    }
    $history[] = [
      'status' => $status,
      'timestamp' => $now,
      'message' => $message !== '' ? $message : ('Status changed to ' . $status . '.'),
    ];
    $task['history'] = $history;
    return $this->save($task);
  }

  /**
   * Stores an uploaded audio file for a task.
   *
   * @param string $taskId
   *   Task identifier.
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile
   *   Uploaded audio file.
   *
   * @return array<string,mixed>
   *   Updated task record.
   */
  public function storeUpload(string $taskId, UploadedFile $uploadedFile): array {
    $this->requireTask($taskId);
    $directory = 'temporary://framesmith-transcription/' . $taskId;
    $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
    if ($extension === '') {
      $extension = 'wav';
    }

    $sourcePath = $uploadedFile->getRealPath();
    if (!is_string($sourcePath) || $sourcePath === '') {
      throw new \RuntimeException('Uploaded file has no readable source path.');
    }

    $destination = $directory . '/audio.' . $extension;
    $storedPath = $this->fileSystem->move($sourcePath, $destination, FileSystemInterface::EXISTS_REPLACE);
    if (!is_string($storedPath) || $storedPath === '') {
      throw new \RuntimeException('Failed to store uploaded audio file.');
    }

    return $this->transition(
      $taskId,
      'uploaded',
      [
        'local_audio_path' => $storedPath,
        'launch_ready' => TRUE,
        'last_error' => '',
      ],
      'Audio uploaded and task is ready to launch.',
    );
  }

  /**
   * Stores detached launch metadata.
   *
   * @param string $taskId
   *   Task identifier.
   * @param array<string,mixed> $launch
   *   Launch metadata.
   *
   * @return array<string,mixed>
   *   Updated task record.
   */
  public function markLaunch(string $taskId, array $launch): array {
    return $this->transition(
      $taskId,
      'launching',
      [
        'launch' => $launch,
        'launch_ready' => TRUE,
        'last_error' => '',
      ],
      'Detached runner launched.',
    );
  }

  /**
   * Marks a task as failed.
   *
   * @param string $taskId
   *   Task identifier.
   * @param string $message
   *   Failure message.
   * @param array<string,mixed> $extra
   *   Extra values to merge.
   *
   * @return array<string,mixed>
   *   Updated task record.
   */
  public function fail(string $taskId, string $message, array $extra = []): array {
    $extra['last_error'] = $message;
    return $this->transition($taskId, 'failed', $extra, $message);
  }

  /**
   * Saves a task record.
   *
   * @param array<string,mixed> $task
   *   Task record.
   *
   * @return array<string,mixed>
   *   Saved task record.
   */
  private function save(array $task): array {
    $taskId = trim((string) ($task['task_id'] ?? ''));
    if ($taskId === '') {
      throw new \InvalidArgumentException('Task record must include task_id.');
    }

    $tasks = $this->all();
    $tasks[$taskId] = $task;
    $this->state->set(self::STATE_KEY, $tasks);
    return $task;
  }

  /**
   * Returns a task or throws.
   *
   * @param string $taskId
   *   Task identifier.
   *
   * @return array<string,mixed>
   *   Task record.
   */
  private function requireTask(string $taskId): array {
    $task = $this->get($taskId);
    if ($task === NULL) {
      throw new \RuntimeException('Unknown Framesmith transcription task: ' . $taskId);
    }
    return $task;
  }

}
