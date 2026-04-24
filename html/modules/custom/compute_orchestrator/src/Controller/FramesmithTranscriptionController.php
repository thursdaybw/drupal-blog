<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Controller;

use Drupal\compute_orchestrator\Service\FramesmithTranscriptionLauncher;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionTaskStore;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Framesmith transcription API endpoints.
 */
final class FramesmithTranscriptionController extends ControllerBase {

  public function __construct(
    private readonly FramesmithTranscriptionTaskStore $taskStore,
    private readonly FramesmithTranscriptionLauncher $launcher,
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
      'task' => $task,
    ]);
  }

  /**
   * Stores uploaded audio for a task.
   */
  public function upload(Request $request): JsonResponse {
    $taskId = trim((string) $request->request->get('task_id', ''));
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
      'task' => $task,
    ]);
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
      'task' => $task,
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
      'task' => $task,
    ]);
  }

}
