<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/VastRestClientInterface.php';
require_once __DIR__ . '/../../../src/Service/SshConnectionContext.php';
require_once __DIR__ . '/../../../src/Service/SshProbeRequest.php';
require_once __DIR__ . '/../../../src/Service/SshProbeExecutorInterface.php';
require_once __DIR__ . '/../../../src/Service/SshKeyPathResolverInterface.php';
require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManager.php';

use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManager;
use Drupal\compute_orchestrator\Service\SshConnectionContext;
use Drupal\compute_orchestrator\Service\SshKeyPathResolverInterface;
use Drupal\compute_orchestrator\Service\SshProbeExecutorInterface;
use Drupal\compute_orchestrator\Service\SshProbeRequest;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
