<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Controller;

use Drupal\compute_orchestrator\Service\FramesmithTranscriptionLauncherInterface;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionTaskStoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Framesmith transcription API endpoints.
 */
final class FramesmithTranscriptionController extends ControllerBase {

  public function __construct(
    private readonly FramesmithTranscriptionTaskStoreInterface $taskStore,
    private readonly FramesmithTranscriptionLauncherInterface $launcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('compute_orchestrator.framesmith_transcription_task_store'),
      $container->get('compute_orchestrator.framesmith_transcription_launcher'),
    );
  }

  /**
   * Starts or resumes a Framesmith transcription task.
   */
  public function start(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }

    $taskId = trim((string) ($payload['task_id'] ?? ''));
    $videoId = trim((string) ($payload['video_id'] ?? ''));
    $autoLaunch = array_key_exists('auto_launch', $payload) ? (bool) $payload['auto_launch'] : TRUE;

    $task = $taskId !== ''
      ? $this->taskStore->get($taskId)
      : $this->taskStore->create(['video_id' => $videoId]);

    if ($task === NULL) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Unknown task_id.',
      ], Response::HTTP_NOT_FOUND);
    }

    if ($videoId !== '' && (string) ($task['video_id'] ?? '') === '') {
      $task = $this->taskStore->merge($taskId ?: (string) $task['task_id'], ['video_id' => $videoId]);
    }

    $taskId = (string) $task['task_id'];
    $uploadReady = trim((string) ($task['local_audio_path'] ?? '')) !== '';
    $launch = NULL;

    if ($autoLaunch && $uploadReady) {
      $launch = $this->launcher->launch($taskId);
    }
    else {
      $task = $this->taskStore->transition(
        $taskId,
        $uploadReady ? 'ready_to_launch' : 'awaiting_upload',
        [
          'launch_ready' => $uploadReady,
          'last_error' => '',
        ],
      );
    }

    $task = $this->taskStore->get($taskId) ?? $task;

    return new JsonResponse([
      'ok' => TRUE,
      'task_id' => $taskId,
      'status' => $task['status'] ?? 'unknown',
      'launched' => (bool) ($launch['launched'] ?? FALSE),
      'launch' => $launch,
      'task' => $this->buildTaskPayload($task),
    ]);
  }

  /**
   * Stores uploaded audio for a task.
   */
  public function upload(Request $request): JsonResponse {
    $taskId = trim((string) (
      $request->request->get('task_id')
      ?? $request->query->get('task_id')
      ?? ''
    ));
    if ($taskId === '') {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'task_id is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Unknown task_id.',
      ], Response::HTTP_NOT_FOUND);
    }

    $uploadedFile = $request->files->get('file');
    if ($uploadedFile === NULL) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'file upload is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    if ($this->isChunkedUploadRequest($request)) {
      return $this->storeChunkedUpload($request, $taskId, $uploadedFile);
    }

    return $this->storeCompleteUpload($request, $taskId, $uploadedFile);
  }

  /**
   * Determines whether this request is one chunk of a larger audio upload.
   */
  private function isChunkedUploadRequest(Request $request): bool {
    return $request->query->has('upload_id')
      || $request->query->has('offset')
      || $request->query->has('size')
      || $request->query->has('total_size');
  }

  /**
   * Stores one complete uploaded audio file and optionally launches work.
   */
  private function storeCompleteUpload(Request $request, string $taskId, UploadedFile $uploadedFile): JsonResponse {
    $task = $this->taskStore->storeUpload($taskId, $uploadedFile);
    $autoLaunch = $request->request->getBoolean('auto_launch', TRUE);
    $launch = NULL;

    if ($autoLaunch) {
      $launch = $this->launcher->launch($taskId);
      $task = $this->taskStore->get($taskId) ?? $task;
    }
    else {
      $task = $this->taskStore->transition($taskId, 'ready_to_launch', ['launch_ready' => TRUE]);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'task_id' => $taskId,
      'status' => $task['status'] ?? 'unknown',
      'launch' => $launch,
      'task' => $this->buildTaskPayload($task),
    ]);
  }

  /**
   * Stores one chunk of a Framesmith transcription audio upload.
   */
  private function storeChunkedUpload(Request $request, string $taskId, UploadedFile $uploadedFile): JsonResponse {
    $uploadId = $this->sanitizeUploadId((string) $request->query->get('upload_id', ''));
    $offset = $request->query->has('offset') ? (int) $request->query->get('offset') : NULL;
    $size = $request->query->has('size') ? (int) $request->query->get('size') : NULL;
    $totalSize = $request->query->has('total_size') ? (int) $request->query->get('total_size') : NULL;

    if ($uploadId === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'upload_id is required for ranged uploads.'], Response::HTTP_BAD_REQUEST);
    }
    if ($offset === NULL || $offset < 0) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'offset must be a non-negative integer.'], Response::HTTP_BAD_REQUEST);
    }
    if ($size === NULL || $size < 1) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'size must be greater than zero.'], Response::HTTP_BAD_REQUEST);
    }
    if ($totalSize === NULL || $totalSize < 1) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'total_size must be greater than zero.'], Response::HTTP_BAD_REQUEST);
    }
    if ($offset >= $totalSize || $offset + $size > $totalSize) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'offset and size are out of range for total_size.'], Response::HTTP_BAD_REQUEST);
    }

    $chunkDirectory = $this->chunkDirectory($taskId, $uploadId);
    if (!is_dir($chunkDirectory) && !mkdir($chunkDirectory, 0775, TRUE) && !is_dir($chunkDirectory)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to prepare ranged upload directory.'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $sourcePath = $uploadedFile->getRealPath();
    if (!is_string($sourcePath) || $sourcePath === '' || !is_readable($sourcePath)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Uploaded range has no readable source path.'], Response::HTTP_BAD_REQUEST);
    }

    $actualSize = filesize($sourcePath);
    if ($actualSize !== $size) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Uploaded range size does not match declared size.',
        'declared_size' => $size,
        'actual_size' => $actualSize,
      ], Response::HTTP_BAD_REQUEST);
    }

    $manifest = $this->loadRangeUploadManifest($chunkDirectory, $uploadId, $totalSize);
    $rangeError = $this->validateNewUploadRange($manifest, $offset, $size);
    if ($rangeError !== NULL) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $rangeError,
        'upload_progress' => $this->buildRangeUploadProgress($manifest, $offset, $size),
      ], Response::HTTP_BAD_REQUEST);
    }

    $partPath = $this->rangePartPath($chunkDirectory, $offset, $size);
    if (!copy($sourcePath, $partPath)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to store uploaded range.'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $manifest['ranges'][] = [
      'offset' => $offset,
      'size' => $size,
      'path' => basename($partPath),
    ];
    usort($manifest['ranges'], static function (array $a, array $b): int {
      return ($a['offset'] ?? 0) <=> ($b['offset'] ?? 0);
    });
    $this->saveRangeUploadManifest($chunkDirectory, $manifest);

    $uploadProgress = $this->recordRangeUploadProgress($taskId, $manifest, $offset, $size);
    if (!$uploadProgress['complete']) {
      return new JsonResponse([
        'ok' => TRUE,
        'task_id' => $taskId,
        'status' => 'partial',
        'mode' => 'ranged',
        'upload_id' => $uploadId,
        'offset' => $offset,
        'size' => $size,
        'total_size' => $totalSize,
        'upload_progress' => $uploadProgress,
      ]);
    }

    $assembledPath = $chunkDirectory . '/audio.wav';
    $assembled = @fopen($assembledPath, 'wb');
    if ($assembled === FALSE) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to assemble uploaded ranges.'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    try {
      foreach ($manifest['ranges'] as $range) {
        $currentPartPath = $chunkDirectory . '/' . (string) $range['path'];
        if (!is_file($currentPartPath) || !is_readable($currentPartPath)) {
          fclose($assembled);
          @unlink($assembledPath);
          return new JsonResponse([
            'ok' => FALSE,
            'error' => 'Missing uploaded range.',
            'missing_offset' => (int) ($range['offset'] ?? 0),
            'upload_progress' => $uploadProgress,
          ], Response::HTTP_BAD_REQUEST);
        }
        $part = @fopen($currentPartPath, 'rb');
        if ($part === FALSE) {
          fclose($assembled);
          @unlink($assembledPath);
          return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to read uploaded range.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        stream_copy_to_stream($part, $assembled);
        fclose($part);
      }
    }
    finally {
      if (is_resource($assembled)) {
        fclose($assembled);
      }
    }

    $assembledUpload = new UploadedFile(
      $assembledPath,
      $taskId . '.wav',
      'audio/wav',
      NULL,
      TRUE,
    );

    $response = $this->storeCompleteUpload($request, $taskId, $assembledUpload);
    $this->removeDirectory($chunkDirectory);
    return $response;
  }

  /**
   * Records backend-visible progress for an in-flight chunked upload.
   *
   * The browser smoke can fail quickly when the UI and Drupal disagree about
   * chunk progress. Without this persisted signal, a broken retry can leave the
   * task in awaiting_upload until the long transcription timeout expires.
   *
   * @return array<string,mixed>
   *   Upload progress payload stored on the task.
   */
  private function loadRangeUploadManifest(string $chunkDirectory, string $uploadId, int $totalSize): array {
    $manifestPath = $chunkDirectory . '/manifest.json';
    if (!is_file($manifestPath)) {
      return [
        'mode' => 'ranged',
        'upload_id' => $uploadId,
        'total_size' => $totalSize,
        'ranges' => [],
      ];
    }

    $decoded = json_decode((string) file_get_contents($manifestPath), TRUE);
    if (!is_array($decoded)) {
      return [
        'mode' => 'ranged',
        'upload_id' => $uploadId,
        'total_size' => $totalSize,
        'ranges' => [],
      ];
    }

    if ((string) ($decoded['upload_id'] ?? '') !== $uploadId || (int) ($decoded['total_size'] ?? 0) !== $totalSize) {
      return [
        'mode' => 'ranged',
        'upload_id' => $uploadId,
        'total_size' => $totalSize,
        'ranges' => [],
      ];
    }

    $decoded['ranges'] = is_array($decoded['ranges'] ?? NULL) ? $decoded['ranges'] : [];
    return $decoded;
  }

  /**
   * Saves the byte-range upload manifest.
   *
   * @param string $chunkDirectory
   *   Directory containing the upload ranges.
   * @param array<string,mixed> $manifest
   *   Manifest payload.
   */
  private function saveRangeUploadManifest(string $chunkDirectory, array $manifest): void {
    file_put_contents($chunkDirectory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Rejects byte ranges that would overlap already stored bytes.
   *
   * @param array<string,mixed> $manifest
   *   Manifest payload.
   * @param int $offset
   *   First byte offset for the incoming range.
   * @param int $size
   *   Size in bytes for the incoming range.
   */
  private function validateNewUploadRange(array $manifest, int $offset, int $size): ?string {
    $end = $offset + $size;
    foreach ($manifest['ranges'] ?? [] as $range) {
      $rangeOffset = (int) ($range['offset'] ?? 0);
      $rangeSize = (int) ($range['size'] ?? 0);
      $rangeEnd = $rangeOffset + $rangeSize;
      $overlaps = $offset < $rangeEnd && $end > $rangeOffset;
      if ($overlaps) {
        return 'Uploaded range overlaps already stored bytes.';
      }
    }
    return NULL;
  }

  /**
   * Records backend-visible progress for an in-flight ranged upload.
   *
   * @param string $taskId
   *   Framesmith transcription task ID.
   * @param array<string,mixed> $manifest
   *   Manifest payload.
   * @param int $offset
   *   First byte offset for the received range.
   * @param int $size
   *   Size in bytes for the received range.
   *
   * @return array<string,mixed>
   *   Upload progress payload stored on the task.
   */
  private function recordRangeUploadProgress(string $taskId, array $manifest, int $offset, int $size): array {
    $progress = $this->buildRangeUploadProgress($manifest, $offset, $size);
    $this->taskStore->merge($taskId, ['upload_progress' => $progress]);
    return $progress;
  }

  /**
   * Builds byte-range upload progress from the manifest.
   *
   * @param array<string,mixed> $manifest
   *   Manifest payload.
   * @param int $offset
   *   First byte offset for the received range.
   * @param int $size
   *   Size in bytes for the received range.
   *
   * @return array<string,mixed>
   *   Upload progress payload.
   */
  private function buildRangeUploadProgress(array $manifest, int $offset, int $size): array {
    $ranges = $manifest['ranges'] ?? [];
    usort($ranges, static function (array $a, array $b): int {
      return ($a['offset'] ?? 0) <=> ($b['offset'] ?? 0);
    });

    $contiguousBytes = 0;
    foreach ($ranges as $range) {
      $rangeOffset = (int) ($range['offset'] ?? 0);
      $rangeSize = (int) ($range['size'] ?? 0);
      if ($rangeOffset !== $contiguousBytes) {
        break;
      }
      $contiguousBytes += $rangeSize;
    }

    $totalSize = (int) ($manifest['total_size'] ?? 0);
    $receivedBytes = 0;
    foreach ($ranges as $range) {
      $receivedBytes += (int) ($range['size'] ?? 0);
    }

    return [
      'mode' => 'ranged',
      'upload_id' => (string) ($manifest['upload_id'] ?? ''),
      'last_received_offset' => $offset,
      'last_received_size' => $size,
      'total_size' => $totalSize,
      'received_bytes' => $receivedBytes,
      'contiguous_bytes' => $contiguousBytes,
      'next_offset' => $contiguousBytes,
      'ranges' => array_map(static function (array $range): array {
        return [
          'offset' => (int) ($range['offset'] ?? 0),
          'size' => (int) ($range['size'] ?? 0),
        ];
      }, $ranges),
      'range_count' => count($ranges),
      'complete' => $totalSize > 0 && $contiguousBytes >= $totalSize,
      'updated_at' => time(),
    ];
  }

  /**
   * Builds the part path for a byte-range upload.
   */
  private function rangePartPath(string $chunkDirectory, int $offset, int $size): string {
    return $chunkDirectory . '/range-' . str_pad((string) $offset, 16, '0', STR_PAD_LEFT) . '-' . $size . '.bin';
  }

  /**
   * Sanitizes a caller-generated upload ID for use in temporary paths.
   */
  private function sanitizeUploadId(string $uploadId): string {
    return preg_replace('/[^A-Za-z0-9._-]/', '_', trim($uploadId)) ?: '';
  }

  /**
   * Builds the temporary directory path for one chunked upload.
   */
  private function chunkDirectory(string $taskId, string $uploadId): string {
    return sys_get_temp_dir() . '/framesmith-transcription-chunks/' . $this->sanitizeUploadId($taskId) . '/' . $uploadId;
  }

  /**
   * Removes a temporary chunk directory after successful finalization.
   */
  private function removeDirectory(string $directory): void {
    if (!is_dir($directory)) {
      return;
    }
    $entries = scandir($directory);
    if ($entries === FALSE) {
      return;
    }
    foreach ($entries as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $directory . '/' . $entry;
      if (is_file($path) || is_link($path)) {
        @unlink($path);
      }
    }
    @rmdir($directory);
  }

  /**
   * Returns task status.
   */
  public function status(Request $request): JsonResponse {
    $taskId = trim((string) $request->query->get('task_id', ''));
    if ($taskId === '') {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'task_id is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Unknown task_id.',
      ], Response::HTTP_NOT_FOUND);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'task_id' => $taskId,
      'status' => $task['status'] ?? 'unknown',
      'transcript_ready' => !empty($task['result']['json']),
      'json_url' => $task['result']['json_url'] ?? NULL,
      'task' => $this->buildTaskPayload($task),
    ]);
  }

  /**
   * Returns task result.
   */
  public function result(Request $request): JsonResponse {
    $taskId = trim((string) $request->query->get('task_id', ''));
    if ($taskId === '') {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'task_id is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Unknown task_id.',
      ], Response::HTTP_NOT_FOUND);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'task_id' => $taskId,
      'status' => $task['status'] ?? 'unknown',
      'result' => $task['result'] ?? NULL,
      'task' => $this->buildTaskPayload($task),
    ]);
  }

  /**
   * Builds the public task payload.
   *
   * Keeps the canonical pool contract link while treating lease blobs as
   * internal debug snapshots rather than authoritative task state.
   *
   * @param array<string,mixed> $task
   *   Raw stored task.
   *
   * @return array<string,mixed>
   *   Public task payload.
   */
  private function buildTaskPayload(array $task): array {
    unset($task['lease'], $task['released_lease']);
    unset($task['runtime_lease_snapshot'], $task['runtime_release_snapshot']);
    if (isset($task['runner_output']) && is_array($task['runner_output'])) {
      $task['runner_output'] = [
        'stdout_tail' => $this->readRunnerTail((string) ($task['runner_output']['stdout_path'] ?? '')),
        'stderr_tail' => $this->readRunnerTail((string) ($task['runner_output']['stderr_path'] ?? '')),
      ];
    }
    return $task;
  }

  /**
   * Reads a short tail from a task-scoped runner output file.
   */
  private function readRunnerTail(string $path): string {
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      return '';
    }
    $contents = trim((string) file_get_contents($path));
    if ($contents === '') {
      return '';
    }
    return substr($contents, -4000);
  }

}
