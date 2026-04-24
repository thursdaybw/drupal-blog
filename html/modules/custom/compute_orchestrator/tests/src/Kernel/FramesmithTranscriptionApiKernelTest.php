<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\compute_orchestrator\Controller\FramesmithTranscriptionController;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionLauncherInterface;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionTaskStoreInterface;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../../src/Controller/FramesmithTranscriptionController.php';
require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionTaskStoreInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionLauncherInterface.php';

/**
 * Verifies the Framesmith transcription controller contract.
 *
 * @group compute_orchestrator
 */
final class FramesmithTranscriptionApiKernelTest extends KernelTestBase {

  /**
   * Modules required for the controller contract test.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'compute_orchestrator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('system', ['sequences']);
  }

  /**
   * Verifies start creates a task waiting for upload.
   */
  public function testStartCreatesTaskAwaitingUpload(): void {
    $controller = FramesmithTranscriptionController::create($this->container);
    $response = $controller->start(Request::create(
      '/api/framesmith/transcription/start',
      'POST',
      [],
      [],
      [],
      [],
      json_encode(['video_id' => 'vid-1'], JSON_THROW_ON_ERROR),
    ));

    $payload = json_decode($response->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($payload['ok']);
    $this->assertSame('awaiting_upload', $payload['status']);
    $this->assertNotEmpty($payload['task_id']);

    $task = $this->container->get('compute_orchestrator.framesmith_transcription_task_store')->get($payload['task_id']);
    $this->assertSame('vid-1', $task['video_id']);
    $this->assertSame('awaiting_upload', $task['status']);
  }

  /**
   * Verifies start launches an existing task with uploaded audio.
   */
  public function testStartLaunchesWhenTaskAlreadyHasUploadedAudio(): void {
    $taskStore = $this->container->get('compute_orchestrator.framesmith_transcription_task_store');
    assert($taskStore instanceof FramesmithTranscriptionTaskStoreInterface);
    $task = $taskStore->create(['video_id' => 'vid-2']);
    $taskStore->merge($task['task_id'], [
      'local_audio_path' => 'temporary://framesmith-transcription/' . $task['task_id'] . '/audio.wav',
    ]);

    $fakeLauncher = new class() implements FramesmithTranscriptionLauncherInterface {

      /**
       * Captured launch calls.
       *
       * @var string[]
       */
      public array $calls = [];

      /**
       * {@inheritdoc}
       */
      public function launch(string $taskId): array {
        $this->calls[] = $taskId;
        return [
          'launched' => TRUE,
          'pid' => 999,
          'command' => 'fake-launch ' . $taskId,
        ];
      }

    };
    $this->container->set('compute_orchestrator.framesmith_transcription_launcher', $fakeLauncher);

    $controller = FramesmithTranscriptionController::create($this->container);
    $response = $controller->start(Request::create(
      '/api/framesmith/transcription/start',
      'POST',
      [],
      [],
      [],
      [],
      json_encode(['task_id' => $task['task_id']], JSON_THROW_ON_ERROR),
    ));

    $payload = json_decode($response->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($payload['ok']);
    $this->assertTrue($payload['launched']);
    $this->assertSame([$task['task_id']], $fakeLauncher->calls);
  }

  /**
   * Verifies status and result return stored task data.
   */
  public function testStatusAndResultExposeStoredTaskData(): void {
    $taskStore = $this->container->get('compute_orchestrator.framesmith_transcription_task_store');
    assert($taskStore instanceof FramesmithTranscriptionTaskStoreInterface);
    $task = $taskStore->create(['video_id' => 'vid-3']);
    $taskStore->transition($task['task_id'], 'completed', [
      'result' => [
        'json' => ['text' => 'hello world', 'segments' => []],
        'json_url' => '/tmp/result.json',
      ],
    ]);

    $controller = FramesmithTranscriptionController::create($this->container);

    $statusResponse = $controller->status(Request::create('/api/framesmith/transcription/status', 'GET', ['task_id' => $task['task_id']]));
    $statusPayload = json_decode($statusResponse->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($statusPayload['ok']);
    $this->assertSame('completed', $statusPayload['status']);
    $this->assertTrue($statusPayload['transcript_ready']);
    $this->assertSame('/tmp/result.json', $statusPayload['json_url']);

    $resultResponse = $controller->result(Request::create('/api/framesmith/transcription/result', 'GET', ['task_id' => $task['task_id']]));
    $resultPayload = json_decode($resultResponse->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($resultPayload['ok']);
    $this->assertSame('hello world', $resultPayload['result']['json']['text']);
  }

}
