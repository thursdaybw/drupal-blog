<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Exception/WorkloadReadinessException.php';
require_once __DIR__ . '/../../../src/Service/Workload/FailureClass.php';
require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/VastRestClientInterface.php';
require_once __DIR__ . '/../../../src/Service/SshConnectionContext.php';
require_once __DIR__ . '/../../../src/Service/SshProbeRequest.php';
require_once __DIR__ . '/../../../src/Service/SshProbeExecutorInterface.php';
require_once __DIR__ . '/../../../src/Service/SshKeyPathResolverInterface.php';
require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManager.php';

use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManager;
use Drupal\compute_orchestrator\Service\SshConnectionContext;
use Drupal\compute_orchestrator\Service\SshKeyPathResolverInterface;
use Drupal\compute_orchestrator\Service\SshProbeExecutorInterface;
use Drupal\compute_orchestrator\Service\SshProbeRequest;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\compute_orchestrator\Service\GenericVllmRuntimeManager
 *
 * @group compute_orchestrator
 */
final class GenericVllmRuntimeManagerTest extends TestCase {

  /**
   * @covers ::provisionFresh
   */
  public function testProvisionFreshForwardsContractCreatedCallback(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_hourly_price')
      ->willReturn(0.5);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('compute_orchestrator.settings')
      ->willReturn($config);

    $callbackCalls = [];
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('provisionInstanceFromOffers')
      ->willReturnCallback(static function (
        array $filters,
        array $excludeRegions,
        int $limit,
        ?float $maxPrice,
        ?float $minPrice,
        array $createOptions,
        int $maxAttempts,
        int $bootTimeoutSeconds,
      ) use (&$callbackCalls): array {
        self::assertSame(20 * 1024, $filters['gpu_ram']['gte']);
        self::assertSame(0.5, $maxPrice);
        self::assertNull($minPrice);
        self::assertSame(20, $limit);
        self::assertSame(5, $maxAttempts);
        self::assertSame(600, $bootTimeoutSeconds);
        self::assertIsCallable($createOptions['on_contract_created'] ?? NULL);
        $createOptions['on_contract_created'](
          '777',
          ['id' => 'offer-777', 'host_id' => 'host-777'],
          ['new_contract' => '777'],
        );
        $callbackCalls[] = 'vast-client-returned';
        return [
          'contract_id' => '777',
          'instance_info' => ['id' => '777'],
          'offer' => ['id' => 'offer-777'],
        ];
      });

    $manager = new GenericVllmRuntimeManager(
      $vastClient,
      new RecordingSshProbeExecutor([]),
      $this->keyPathResolver('/tmp/test-key'),
      $configFactory,
      $this->loggerFactory(),
    );

    $result = $manager->provisionFresh([
      'mode' => 'whisper',
      'gpu_ram_gte' => 20,
      'on_contract_created' => static function (
        string $contractId,
        array $offer,
        array $createResponse,
      ) use (&$callbackCalls): void {
        $callbackCalls[] = [
          'contract_id' => $contractId,
          'offer_id' => $offer['id'] ?? '',
          'new_contract' => $createResponse['new_contract'] ?? '',
        ];
      },
    ], 'test-image:latest');

    self::assertSame('777', $result['contract_id']);
    self::assertSame([
      [
        'contract_id' => '777',
        'offer_id' => 'offer-777',
        'new_contract' => '777',
      ],
      'vast-client-returned',
    ], $callbackCalls);
  }

  /**
   * @covers ::startWorkload
   */
  public function testStartWorkloadFailureIncludesProbeDiagnosticsWhenOutputIsEmpty(): void {
    $probeExecutor = new RecordingSshProbeExecutor([
      [
        'ok' => FALSE,
        'transport_ok' => TRUE,
        'failure_kind' => 'command',
        'exit_code' => 255,
        'stdout' => '',
        'stderr' => '',
        'exception' => NULL,
      ],
      [
        'ok' => FALSE,
        'transport_ok' => TRUE,
        'failure_kind' => 'command',
        'exit_code' => 255,
        'stdout' => '',
        'stderr' => '',
        'exception' => NULL,
      ],
    ]);

    $manager = new GenericVllmRuntimeManager(
      $this->createMock(VastRestClientInterface::class),
      $probeExecutor,
      $this->keyPathResolver('/tmp/test-key'),
      $this->createMock(ConfigFactoryInterface::class),
      $this->loggerFactory(),
    );

    try {
      $manager->startWorkload(
        [
          'ssh_host' => 'ssh6.vast.ai',
          'ssh_port' => 16908,
          'ssh_user' => 'root',
        ],
        [
          'mode' => 'whisper',
          'model' => 'openai/whisper-large-v3-turbo',
        ],
      );
      $this->fail('Expected start workload to fail.');
    }
    catch (\RuntimeException $exception) {
      $message = $exception->getMessage();
      self::assertStringContainsString('Remote start-model failed:', $message);
      self::assertStringContainsString('probe=start_model', $message);
      self::assertStringContainsString('host=ssh6.vast.ai', $message);
      self::assertStringContainsString('port=16908', $message);
      self::assertStringContainsString('user=root', $message);
      self::assertStringContainsString('transport_ok=true', $message);
      self::assertStringContainsString('failure_kind=command', $message);
      self::assertStringContainsString('exit_code=255', $message);
      self::assertStringContainsString('stderr=(empty)', $message);
      self::assertStringContainsString('stdout=(empty)', $message);
      self::assertStringContainsString('exception=(empty)', $message);
      self::assertStringContainsString('/opt/vllm/bin/start-model.sh', $message);
      self::assertStringContainsString('whisper', $message);
      self::assertStringContainsString('openai/whisper-large-v3-turbo', $message);
    }

    self::assertSame(['status_before_start', 'start_model'], $probeExecutor->probeNames());
  }

  /**
   * @covers ::startWorkload
   */
  public function testStartWorkloadDoesNotRestartMatchingWarmupProcessWhenStatusIsStale(): void {
    $probeExecutor = new RecordingSshProbeExecutor([
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => "state=stale
",
        'stderr' => '',
        'exception' => NULL,
      ],
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => "root 115 1 59 04:03 ? 00:00:07 python3 -m vllm.entrypoints.openai.api_server --model openai/whisper-large-v3-turbo --host 0.0.0.0 --port 8000 --dtype float16 --download-dir /opt/hf-cache
",
        'stderr' => '',
        'exception' => NULL,
      ],
    ]);

    $manager = new GenericVllmRuntimeManager(
      $this->createMock(VastRestClientInterface::class),
      $probeExecutor,
      $this->keyPathResolver('/tmp/test-key'),
      $this->createMock(ConfigFactoryInterface::class),
      $this->loggerFactory(),
    );

    $manager->startWorkload(
      [
        'ssh_host' => 'ssh6.vast.ai',
        'ssh_port' => 16908,
        'ssh_user' => 'root',
      ],
      [
        'mode' => 'whisper',
        'model' => 'openai/whisper-large-v3-turbo',
      ],
    );

    self::assertSame(
      ['status_before_start', 'processes_before_start'],
      $probeExecutor->probeNames(),
      'A stale status with a matching warming vLLM process must not restart the model.',
    );
  }

  /**
   * @covers ::startWorkload
   */
  public function testStartWorkloadStillStartsWhenStaleProcessDoesNotMatchRequestedModel(): void {
    $probeExecutor = new RecordingSshProbeExecutor([
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => "state=stale
",
        'stderr' => '',
        'exception' => NULL,
      ],
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => "root 99 1 python3 -m vllm.entrypoints.openai.api_server --model Qwen/Qwen2-VL-7B-Instruct --port 8000
",
        'stderr' => '',
        'exception' => NULL,
      ],
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => "Started model server: mode=whisper model=openai/whisper-large-v3-turbo pid=116
",
        'stderr' => '',
        'exception' => NULL,
      ],
    ]);

    $manager = new GenericVllmRuntimeManager(
      $this->createMock(VastRestClientInterface::class),
      $probeExecutor,
      $this->keyPathResolver('/tmp/test-key'),
      $this->createMock(ConfigFactoryInterface::class),
      $this->loggerFactory(),
    );

    $manager->startWorkload(
      [
        'ssh_host' => 'ssh6.vast.ai',
        'ssh_port' => 16908,
        'ssh_user' => 'root',
      ],
      [
        'mode' => 'whisper',
        'model' => 'openai/whisper-large-v3-turbo',
      ],
    );

    self::assertSame(
      ['status_before_start', 'processes_before_start', 'start_model'],
      $probeExecutor->probeNames(),
      'A stale status without a matching process should still start the model.',
    );
  }

  /**
   * @covers ::startWorkload
   */
  public function testStartWorkloadTreatsStaleMissingProcessAfterWarmupAsRuntimeLost(): void {
    $probeExecutor = new RecordingSshProbeExecutor([
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => "state=stale
",
        'stderr' => '',
        'exception' => NULL,
      ],
      [
        'ok' => TRUE,
        'transport_ok' => TRUE,
        'failure_kind' => 'none',
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
        'exception' => NULL,
      ],
    ]);

    $manager = new GenericVllmRuntimeManager(
      $this->createMock(VastRestClientInterface::class),
      $probeExecutor,
      $this->keyPathResolver('/tmp/test-key'),
      $this->createMock(ConfigFactoryInterface::class),
      $this->loggerFactory(),
    );

    try {
      $manager->startWorkload(
        [
          'ssh_host' => 'ssh6.vast.ai',
          'ssh_port' => 16908,
          'ssh_user' => 'root',
        ],
        [
          'mode' => 'whisper',
          'model' => 'openai/whisper-large-v3-turbo',
          'fail_stale_without_process_after_warmup' => TRUE,
        ],
      );
      $this->fail('Expected missing warmup process to be treated as runtime lost.');
    }
    catch (WorkloadReadinessException $exception) {
      self::assertSame(FailureClass::RUNTIME_LOST, $exception->getFailureClass());
      self::assertStringContainsString('no matching vLLM process', $exception->getMessage());
    }

    self::assertSame(
      ['status_before_start', 'processes_before_start'],
      $probeExecutor->probeNames(),
      'A stale status with lost warmup process must not call start-model again.',
    );
  }

  /**
   * Builds a key-path resolver stub.
   */
  private function keyPathResolver(string $path): SshKeyPathResolverInterface {
    $resolver = $this->createMock(SshKeyPathResolverInterface::class);
    $resolver->method('resolveRequiredPath')->willReturn($path);
    return $resolver;
  }

  /**
   * Builds a logger factory that returns a null logger.
   */
  private function loggerFactory(): LoggerChannelFactoryInterface {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn(new NullLogger());
    return $factory;
  }

}

/**
 * Records SSH probes and returns queued normalized results.
 */
final class RecordingSshProbeExecutor implements SshProbeExecutorInterface {

  /**
   * Queued normalized SSH probe results.
   *
   * @var array<int,array<string,mixed>>
   */
  private array $results;

  /**
   * Probe names recorded in call order.
   *
   * @var array<int,string>
   */
  private array $probeNames = [];

  /**
   * Constructs the SSH probe test double.
   *
   * @param array<int,array<string,mixed>> $results
   *   Results returned for each probe call.
   */
  public function __construct(array $results) {
    $this->results = array_values($results);
  }

  /**
   * Records one SSH probe request and returns the next queued result.
   *
   * {@inheritdoc}
   */
  public function run(SshConnectionContext $context, SshProbeRequest $request): array {
    $this->probeNames[] = $request->name;
    if ($this->results === []) {
      throw new \RuntimeException('No queued SSH probe result for ' . $request->name);
    }
    return array_shift($this->results);
  }

  /**
   * Returns the probe names that were executed.
   *
   * @return array<int,string>
   *   Probe names in call order.
   */
  public function probeNames(): array {
    return $this->probeNames;
  }

}
