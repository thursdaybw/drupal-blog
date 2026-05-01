<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\compute_orchestrator\Controller\FramesmithTranscriptionController;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionLauncherInterface;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionTaskStoreInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
    $this->installSequencesTable();
  }

  /**
   * Installs the sequences table without KernelTestBase::installSchema().
   */
  private function installSequencesTable(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('sequences')) {
      return;
    }

    $schema->createTable('sequences', [
      'description' => 'Stores IDs.',
      'fields' => [
        'value' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['value'],
    ]);
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
   * Verifies chunked upload finalizes audio on the last chunk.
   */
  public function testRangedUploadFinalizesAudioWhenAllBytesArrive(): void {
    $taskStore = $this->container->get('compute_orchestrator.framesmith_transcription_task_store');
    assert($taskStore instanceof FramesmithTranscriptionTaskStoreInterface);
    $task = $taskStore->create(['video_id' => 'vid-chunked']);

    $controller = FramesmithTranscriptionController::create($this->container);

    $firstResponse = $controller->upload($this->buildChunkUploadRequest(
      $task['task_id'],
      'upload-test-1',
      0,
      4,
      8,
      'RIFF',
      'part-0.bin',
    ));
    $firstPayload = json_decode($firstResponse->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertTrue($firstPayload['ok']);
    $this->assertSame('partial', $firstPayload['status']);
    $this->assertSame('ranged', $firstPayload['mode']);
    $this->assertSame(0, $firstPayload['offset']);
    $this->assertSame(4, $firstPayload['size']);

    $storedAfterFirstChunk = $taskStore->get($task['task_id']);
    $this->assertSame('', $storedAfterFirstChunk['local_audio_path']);

    $secondResponse = $controller->upload($this->buildChunkUploadRequest(
      $task['task_id'],
      'upload-test-1',
      4,
      2,
      8,
      'WA',
      'part-1.bin',
    ));
    $secondPayload = json_decode($secondResponse->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertTrue($secondPayload['ok']);
    $this->assertSame('partial', $secondPayload['status']);
    $this->assertSame(6, $secondPayload['upload_progress']['next_offset']);

    $finalResponse = $controller->upload($this->buildChunkUploadRequest(
      $task['task_id'],
      'upload-test-1',
      6,
      2,
      8,
      'VE',
      'part-2.bin',
    ));
    $finalPayload = json_decode($finalResponse->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertTrue($finalPayload['ok']);
    $this->assertSame('ready_to_launch', $finalPayload['status']);
    $this->assertNull($finalPayload['launch']);

    $storedAfterFinalChunk = $taskStore->get($task['task_id']);
    $this->assertSame('ready_to_launch', $storedAfterFinalChunk['status']);
    $this->assertNotSame('', $storedAfterFinalChunk['local_audio_path']);

    $resolvedPath = $this->container->get('file_system')->realpath($storedAfterFinalChunk['local_audio_path']);
    $this->assertIsString($resolvedPath);
    $this->assertSame('RIFFWAVE', file_get_contents($resolvedPath));
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
          'stdout_path' => '/tmp/stdout.log',
          'stderr_path' => '/tmp/stderr.log',
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
    $this->assertArrayHasKey('stdout_path', $payload['launch']);
    $this->assertArrayHasKey('stderr_path', $payload['launch']);
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
      'runtime_contract_id' => '35456908',
      'runtime_lease_snapshot' => ['contract_id' => '35456908'],
      'runtime_release_snapshot' => ['contract_id' => '35456908'],
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
    $this->assertSame('35456908', $statusPayload['task']['runtime_contract_id']);
    $this->assertArrayHasKey('runner_output', $statusPayload['task']);
    $this->assertArrayHasKey('stdout_tail', $statusPayload['task']['runner_output']);
    $this->assertArrayHasKey('stderr_tail', $statusPayload['task']['runner_output']);
    $this->assertArrayHasKey('launch_debug', $statusPayload['task']);
    $this->assertArrayNotHasKey('runtime_lease_snapshot', $statusPayload['task']);
    $this->assertArrayNotHasKey('runtime_release_snapshot', $statusPayload['task']);

    $resultResponse = $controller->result(Request::create('/api/framesmith/transcription/result', 'GET', ['task_id' => $task['task_id']]));
    $resultPayload = json_decode($resultResponse->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($resultPayload['ok']);
    $this->assertSame('hello world', $resultPayload['result']['json']['text']);
  }

  /**
   * Verifies the real launcher records output-seam debug data.
   */
  public function testRealLauncherCapturesLaunchDebugWhenOutputPathIsMissing(): void {
    $taskStore = $this->container->get('compute_orchestrator.framesmith_transcription_task_store');
    assert($taskStore instanceof FramesmithTranscriptionTaskStoreInterface);
    $launcher = $this->container->get('compute_orchestrator.framesmith_transcription_launcher');
    assert($launcher instanceof FramesmithTranscriptionLauncherInterface);

    $task = $taskStore->create(['video_id' => 'launch-debug-video']);
    $taskStore->merge($task['task_id'], [
      'local_audio_path' => 'temporary://framesmith-transcription/' . $task['task_id'] . '/audio.wav',
      'launch_ready' => TRUE,
    ]);

    $launch = $launcher->launch($task['task_id']);
    $stored = $taskStore->get($task['task_id']);

    $this->assertNotNull($stored);
    $this->assertIsArray($launch);
    $this->assertSame('launching', $stored['status']);
    $this->assertSame((string) $launch['stdout_path'], (string) $stored['runner_output']['stdout_path']);
    $this->assertSame((string) $launch['stderr_path'], (string) $stored['runner_output']['stderr_path']);
    $this->assertSame('proc_closed', $stored['launch_debug']['stage']);
    $this->assertNotSame('', (string) $stored['launch_debug']['command']);
    $this->assertStringContainsString('HOME=', (string) $stored['launch_debug']['command']);
    $this->assertStringContainsString('XDG_CACHE_HOME=', (string) $stored['launch_debug']['command']);
    $this->assertSame(
      sys_get_temp_dir() . '/framesmith-drush-home',
      $stored['launch_debug']['process_environment']['HOME'] ?? NULL,
    );
    $this->assertNotSame('', (string) $stored['launch_debug']['drush_binary']);
    $this->assertArrayHasKey('output_directory_exists', $stored['launch_debug']);
    $this->assertArrayHasKey('stdout_exists', $stored['launch_debug']);
    $this->assertArrayHasKey('stderr_exists', $stored['launch_debug']);
    $this->assertArrayHasKey('proc_stdout', $stored['launch_debug']);
    $this->assertArrayHasKey('proc_stderr', $stored['launch_debug']);
    $this->assertArrayHasKey('proc_exit_code', $stored['launch_debug']);
  }

  /**
   * Builds a request carrying one uploaded audio chunk.
   */
  private function buildChunkUploadRequest(
    string $taskId,
    string $uploadId,
    int $offset,
    int $size,
    int $totalSize,
    string $contents,
    string $filename,
  ): Request {
    $path = tempnam(sys_get_temp_dir(), 'framesmith-upload-test-');
    $this->assertIsString($path);
    file_put_contents($path, $contents);

    $request = Request::create(
      '/api/framesmith/transcription/upload',
      'POST',
      [
        'task_id' => $taskId,
        'auto_launch' => '0',
      ],
      [],
      [
        'file' => new UploadedFile($path, $filename, 'audio/wav', NULL, TRUE),
      ],
    );
    $request->query->replace([
      'task_id' => $taskId,
      'upload_id' => $uploadId,
      'offset' => $offset,
      'size' => $size,
      'total_size' => $totalSize,
    ]);
    return $request;
  }

}
