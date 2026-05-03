<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Exception/AcquirePendingException.php';
require_once __DIR__ . '/../../../src/Service/VllmPoolLeaseBrokerInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithRuntimeLeaseManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithVllmPoolLeaseManager.php';

use Drupal\compute_orchestrator\Exception\AcquirePendingException;
use Drupal\compute_orchestrator\Service\FramesmithVllmPoolLeaseManager;
use Drupal\compute_orchestrator\Service\VllmPoolLeaseBrokerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\compute_orchestrator\Service\FramesmithVllmPoolLeaseManager
 *
 * @group compute_orchestrator
 */
final class FramesmithVllmPoolLeaseManagerTest extends TestCase {

  /**
   * @covers ::acquireWhisperRuntime
   */
  public function testAcquireWhisperRuntimeRetriesPendingWarmupUntilReady(): void {
    $readyRecord = [
      'contract_id' => '35456908',
      'lease_token' => 'lease-token-1',
      'host' => '180.21.170.235',
      'port' => '41519',
      'url' => 'http://180.21.170.235:41519',
      'current_workload_mode' => 'whisper',
      'current_model' => 'openai/whisper-large-v3-turbo',
    ];
    $broker = new QueuedVllmPoolLeaseBroker([
      AcquirePendingException::fromProgress(
        'Step 4/4: Waiting for vLLM API (/v1/models). Result: Not ready yet. Next: retry.',
        '35456908',
        ['step' => 4, 'label' => 'Waiting for vLLM API (/v1/models)'],
      ),
      $readyRecord,
    ]);

    $manager = new FramesmithVllmPoolLeaseManager($broker, 30, 0);
    $lease = $manager->acquireWhisperRuntime();

    self::assertSame(2, $broker->acquireCallCount());
    self::assertSame('35456908', $lease['contract_id']);
    self::assertSame('lease-token-1', $lease['lease_token']);
    self::assertSame('whisper', $lease['current_workload_mode']);
    self::assertSame('openai/whisper-large-v3-turbo', $lease['current_model']);
    self::assertSame($readyRecord, $lease['pool_record']);
    self::assertSame([
      ['workload' => 'whisper', 'bootstrap_timeout' => 1, 'workload_timeout' => 1],
      ['workload' => 'whisper', 'bootstrap_timeout' => 1, 'workload_timeout' => 1],
    ], $broker->acquireCalls());
  }

  /**
   * @covers ::acquireWhisperRuntime
   */
  public function testAcquireWhisperRuntimeDoesNotSpendProgressingHostBudgetOnPriorVastWork(): void {
    $clock = new ScriptedAcquireClock();
    $readyRecord = [
      'contract_id' => '36045574',
      'lease_token' => 'lease-token-36045574',
      'host' => '1.2.3.4',
      'port' => '22097',
      'url' => 'http://1.2.3.4:22097',
      'current_workload_mode' => 'whisper',
      'current_model' => 'openai/whisper-large-v3-turbo',
    ];
    $broker = new ScriptedVastLifecycleLeaseBroker($clock, [
      [
        'advance' => 540,
        'exception' => $this->pendingProgress(
          '36045449',
          'ssh_bootstrap',
          'Waiting for first Vast host to bootstrap',
        ),
      ],
      [
        'advance' => 320,
        'exception' => $this->pendingProgress(
          '36045574',
          'ssh_bootstrap',
          'Waiting for replacement Vast host to bootstrap',
        ),
      ],
      [
        'advance' => 30,
        'exception' => $this->pendingProgress(
          '36045574',
          'start_workload',
          'start-model succeeded; waiting for /v1/models',
        ),
      ],
      [
        'advance' => 60,
        'exception' => $this->pendingProgress(
          '36045574',
          'workload_ready_probe',
          'API not listening yet on :8000',
        ),
      ],
      [
        'advance' => 40,
        'result' => $readyRecord,
      ],
    ]);

    $manager = new FramesmithVllmPoolLeaseManager(
      $broker,
      900,
      0,
      1800,
      [$clock, 'now'],
      [$clock, 'sleep'],
    );

    $lease = $manager->acquireWhisperRuntime();

    self::assertSame('36045574', $lease['contract_id']);
    self::assertSame(990, $clock->now());
    self::assertSame(5, $broker->acquireCallCount());
  }

  /**
   * Builds a retryable progress exception for scripted Vast lifecycle tests.
   */
  private function pendingProgress(string $contractId, string $phase, string $result): AcquirePendingException {
    return AcquirePendingException::fromProgress(
      'Step pending: ' . $result,
      $contractId,
      [
        'step' => $phase === 'workload_ready_probe' ? 4 : 2,
        'step_total' => 4,
        'label' => $phase,
        'result' => $result,
        'next' => 'retry',
        'phase' => $phase,
        'action' => $result,
      ],
    );
  }

  /**
   * @covers ::releaseRuntime
   */
  public function testReleaseRuntimeDelegatesToPoolBroker(): void {
    $broker = new QueuedVllmPoolLeaseBroker([]);
    $broker->setReleaseRecord(['contract_id' => '35456908', 'lease_status' => 'available']);
    $manager = new FramesmithVllmPoolLeaseManager($broker, 30, 0);

    self::assertSame(
      ['contract_id' => '35456908', 'lease_status' => 'available'],
      $manager->releaseRuntime('35456908'),
    );
    self::assertSame(['35456908'], $broker->releaseCalls());
  }

}

/**
 * Test double for the pool broker used by Framesmith lease management.
 */
final class QueuedVllmPoolLeaseBroker implements VllmPoolLeaseBrokerInterface {

  /**
   * Queued acquire results or exceptions.
   *
   * @var array<int,array<string,mixed>|\Throwable>
   */
  private array $results;

  /**
   * Recorded acquire call metadata.
   *
   * @var array<int,array<string,mixed>>
   */
  private array $acquireCalls = [];

  /**
   * Contract IDs passed to release.
   *
   * @var array<int,string>
   */
  private array $releaseCalls = [];

  /**
   * Payload returned from release.
   *
   * @var array<string,mixed>
   */
  private array $releaseRecord = [];

  /**
   * Constructs the pool broker test double.
   *
   * @param array<int,array<string,mixed>|\Throwable> $results
   *   Queued acquire results.
   */
  public function __construct(array $results) {
    $this->results = array_values($results);
  }

  /**
   * Records one acquire request and returns the next queued result.
   *
   * {@inheritdoc}
   */
  public function acquire(
    string $workload,
    ?string $modelOverride = NULL,
    bool $allowFresh = TRUE,
    ?int $bootstrapTimeoutSeconds = NULL,
    ?int $workloadTimeoutSeconds = NULL,
  ): array {
    $this->acquireCalls[] = [
      'workload' => $workload,
      'bootstrap_timeout' => $bootstrapTimeoutSeconds,
      'workload_timeout' => $workloadTimeoutSeconds,
    ];
    if ($this->results === []) {
      throw new \RuntimeException('No queued acquire result.');
    }
    $result = array_shift($this->results);
    if ($result instanceof \Throwable) {
      throw $result;
    }
    return $result;
  }

  /**
   * Records one release request and returns the configured release record.
   *
   * {@inheritdoc}
   */
  public function release(string $contractId): array {
    $this->releaseCalls[] = $contractId;
    return $this->releaseRecord;
  }

  /**
   * Sets the release return payload.
   *
   * @param array<string,mixed> $record
   *   Release return payload.
   */
  public function setReleaseRecord(array $record): void {
    $this->releaseRecord = $record;
  }

  /**
   * Returns how many acquire calls were made.
   */
  public function acquireCallCount(): int {
    return count($this->acquireCalls);
  }

  /**
   * Returns acquire call metadata.
   *
   * @return array<int,array<string,mixed>>
   *   Acquire call metadata.
   */
  public function acquireCalls(): array {
    return $this->acquireCalls;
  }

  /**
   * Returns release call metadata.
   *
   * @return array<int,string>
   *   Released contract IDs.
   */
  public function releaseCalls(): array {
    return $this->releaseCalls;
  }

}

/**
 * Deterministic clock for Vast lifecycle tests.
 */
final class ScriptedAcquireClock {

  public function __construct(private int $now = 0) {}

  /**
   * Returns current simulated timestamp.
   */
  public function now(): int {
    return $this->now;
  }

  /**
   * Advances simulated time.
   */
  public function advance(int $seconds): void {
    $this->now += max(0, $seconds);
  }

  /**
   * Sleep callback used by FramesmithVllmPoolLeaseManager.
   */
  public function sleep(int $seconds): void {
    $this->advance($seconds);
  }

}

/**
 * Scripted Vast/vLLM lease broker for progress-budget tests.
 */
final class ScriptedVastLifecycleLeaseBroker implements VllmPoolLeaseBrokerInterface {

  /**
   * Recorded acquire calls.
   *
   * @var array<int,array<string,mixed>>
   */
  private array $acquireCalls = [];

  /**
   * Constructs the scripted broker.
   *
   * @param \Drupal\Tests\compute_orchestrator\Unit\ScriptedAcquireClock $clock
   *   Simulated clock shared with the lease manager.
   * @param array<int,array<string,mixed>> $events
   *   Each event may contain advance, exception, or result.
   */
  public function __construct(
    private readonly ScriptedAcquireClock $clock,
    private array $events,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function acquire(
    string $workload,
    ?string $modelOverride = NULL,
    bool $allowFresh = TRUE,
    ?int $bootstrapTimeoutSeconds = NULL,
    ?int $workloadTimeoutSeconds = NULL,
  ): array {
    $this->acquireCalls[] = [
      'workload' => $workload,
      'bootstrap_timeout' => $bootstrapTimeoutSeconds,
      'workload_timeout' => $workloadTimeoutSeconds,
      'at' => $this->clock->now(),
    ];
    if ($this->events === []) {
      throw new \RuntimeException('Scripted Vast lifecycle exhausted.');
    }
    $event = array_shift($this->events);
    $this->clock->advance((int) ($event['advance'] ?? 0));
    if (($event['exception'] ?? NULL) instanceof \Throwable) {
      throw $event['exception'];
    }
    if (isset($event['result']) && is_array($event['result'])) {
      return $event['result'];
    }
    throw new \RuntimeException('Invalid scripted Vast lifecycle event.');
  }

  /**
   * {@inheritdoc}
   */
  public function release(string $contractId): array {
    return ['contract_id' => $contractId, 'lease_status' => 'available'];
  }

  /**
   * Returns acquire call count.
   */
  public function acquireCallCount(): int {
    return count($this->acquireCalls);
  }

}
