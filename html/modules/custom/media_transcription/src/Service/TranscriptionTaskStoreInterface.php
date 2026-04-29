<?php

declare(strict_types=1);

namespace Drupal\media_transcription\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Defines storage operations for transcription tasks.
 */
interface TranscriptionTaskStoreInterface {

  /**
   * Creates a new task.
   *
   * @param array<string,mixed> $values
   *   Optional seed values.
   *
   * @return array<string,mixed>
   *   Saved task record.
   */
  public function create(array $values = []): array;

  /**
   * Returns all tasks.
   *
   * @return array<string,array<string,mixed>>
   *   Task records keyed by task ID.
   */
  public function all(): array;

  /**
   * Returns one task or NULL.
   *
   * @param string $taskId
   *   Task identifier.
   *
   * @return array<string,mixed>|null
   *   Task record or NULL.
   */
  public function get(string $taskId): ?array;

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
  public function merge(string $taskId, array $values): array;

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
  public function transition(string $taskId, string $status, array $extra = [], string $message = ''): array;

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
  public function storeUpload(string $taskId, UploadedFile $uploadedFile): array;

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
  public function markLaunch(string $taskId, array $launch): array;

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
  public function fail(string $taskId, string $message, array $extra = []): array;

}
