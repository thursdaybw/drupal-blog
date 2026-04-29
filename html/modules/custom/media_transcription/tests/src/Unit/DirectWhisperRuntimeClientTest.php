<?php

declare(strict_types=1);

namespace Drupal\Tests\media_transcription\Unit;

use Drupal\compute_orchestrator\Service\BadHostRegistry;
use Drupal\media_transcription\Service\WhisperRuntimeClientInterface;
use Drupal\media_transcription\Service\DirectWhisperRuntimeClient;
use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManagerInterface;
use Drupal\compute_orchestrator\Service\VastInstanceLifecycleClientInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drupal\compute_orchestrator\Service\VllmPoolRepositoryInterface;
use Drupal\compute_orchestrator\Service\VllmWorkloadCatalogInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

require_once __DIR__ . '/../../../../media_transcription/src/Service/WhisperRuntimeClientInterface.php';
require_once __DIR__ . '/../../../../media_transcription/src/Service/DirectWhisperRuntimeClient.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/VllmPoolManager.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/BadHostRegistry.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/VllmPoolRepositoryInterface.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/VllmWorkloadCatalogInterface.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/GenericVllmRuntimeManagerInterface.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/VastInstanceLifecycleClientInterface.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/VastRestClientInterface.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Exception/AcquirePendingException.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Exception/WorkloadReadinessException.php';
require_once __DIR__ . '/../../../../compute_orchestrator/src/Service/Workload/FailureClass.php';

/**
 * Tests the transitional direct Framesmith compute client.
 */
final class DirectWhisperRuntimeClientTest extends TestCase {

  /**
   * Tests the direct client is still interface-compatible.
   */
  public function testDirectClientImplementsComputeRuntimeInterface(): void {
    $client = $this->newClient(new DirectClientTestLogger());

    $this->assertInstanceOf(WhisperRuntimeClientInterface::class, $client);
  }

  /**
   * Tests release warns loudly and writes a log entry on the direct path.
   */
  public function testReleaseRuntimeWarnsAndLogsTransitionalDirectPath(): void {
    $logger = new DirectClientTestLogger();
    $client = $this->newClient($logger);

    $capturedWarning = NULL;
    set_error_handler(static function (int $severity, string $message) use (&$capturedWarning): bool {
      if ($severity === E_USER_WARNING) {
        $capturedWarning = $message;
        return TRUE;
      }
      return FALSE;
    });
    try {
      $released = $client->releaseRuntime('contract-1');
    }
    finally {
      restore_error_handler();
    }

    $this->assertStringStartsWith(
      'The transitional direct in-process Whisper runtime client is in use.',
      (string) $capturedWarning,
    );
    $this->assertSame('contract-1', $released['contract_id']);
    $this->assertSame('available', $released['lease_status']);
    $this->assertCount(1, $logger->records);
    $this->assertSame('warning', $logger->records[0]['level']);
    $this->assertSame('releaseRuntime', $logger->records[0]['context']['operation']);
  }

  /**
   * Builds a direct compute runtime client with an in-memory pool.
   */
  private function newClient(DirectClientTestLogger $logger): DirectWhisperRuntimeClient {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
      return $default;
    });

    $manager = new VllmPoolManager(
      new DirectClientTestPoolRepository([
        'contract-1' => [
          'contract_id' => 'contract-1',
          'lease_status' => 'leased',
          'lease_token' => 'token-1',
          'runtime_state' => 'running',
          'current_workload_mode' => 'whisper',
          'current_model' => 'openai/whisper-large-v3-turbo',
          'url' => 'http://127.0.0.1:8000',
          'source' => 'unit_test',
        ],
      ]),
      $this->createMock(VllmWorkloadCatalogInterface::class),
      $this->createMock(GenericVllmRuntimeManagerInterface::class),
      $this->createMock(VastInstanceLifecycleClientInterface::class),
      $this->createMock(VastRestClientInterface::class),
      $state,
      new BadHostRegistry($state),
      3,
      0,
    );

    return new DirectWhisperRuntimeClient($manager, $logger);
  }

}

/**
 * In-memory pool repository for direct client tests.
 */
final class DirectClientTestPoolRepository implements VllmPoolRepositoryInterface {

  /**
   * Constructs an in-memory pool repository.
   *
   * @param array<string,array<string,mixed>> $records
   *   Initial records.
   */
  public function __construct(
    private array $records,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    return $this->records;
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $contractId): ?array {
    return $this->records[$contractId] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $record): void {
    $this->records[(string) $record['contract_id']] = $record;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $contractId): void {
    unset($this->records[$contractId]);
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->records = [];
  }

}

/**
 * Collecting logger for direct client tests.
 */
final class DirectClientTestLogger extends AbstractLogger {

  /**
   * Collected log records.
   *
   * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []): void {
    $this->records[] = [
      'level' => (string) $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

}
