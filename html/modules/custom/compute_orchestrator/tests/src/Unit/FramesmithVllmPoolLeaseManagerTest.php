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
