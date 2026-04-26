<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

use Drupal\compute_orchestrator\Exception\AcquirePendingException;
use Drupal\compute_orchestrator\Service\BadHostRegistry;
use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManagerInterface;
use Drupal\compute_orchestrator\Service\VastInstanceLifecycleClientInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drupal\compute_orchestrator\Service\VllmPoolRepositoryInterface;
use Drupal\compute_orchestrator\Service\VllmWorkloadCatalogInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Service/VllmPoolManager.php';
require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/VastInstanceLifecycleClientInterface.php';
require_once __DIR__ . '/../../../src/Service/VastRestClientInterface.php';
require_once __DIR__ . '/../../../src/Service/VllmPoolRepositoryInterface.php';
require_once __DIR__ . '/../../../src/Service/VllmWorkloadCatalogInterface.php';
require_once __DIR__ . '/../../../src/Exception/AcquirePendingException.php';
require_once __DIR__ . '/../../../src/Exception/WorkloadReadinessException.php';
require_once __DIR__ . '/../../../src/Service/Workload/FailureClass.php';
require_once __DIR__ . '/../../../src/Service/BadHostRegistry.php';

/**
 * Deterministic state-machine tests for pooled acquire behavior.
 */
final class VllmPoolStateMachineTest extends TestCase {

  /**
   * Tests reconcile restores a drifted stopped instance to available.
   */
  public function testReconcileNormalizesStoppedReusableInstance(): void {
    $repository = new StateMachinePoolRepository([
      '100' => [
        'contract_id' => '100',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'whisper',
        'current_model' => 'openai/whisper-large-v3-turbo',
        'lease_status' => 'unavailable',
        'host' => '198.53.64.194',
        'port' => '40537',
        'url' => 'http://198.53.64.194:40537',
        'source' => 'fresh_fallback',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => 'stale failure',
        'lease_token' => '',
        'leased_at' => 0,
        'lease_expires_at' => 0,
      ],
    ]);

    $catalog = new FakeWorkloadCatalog();
    $state = $this->createMock(StateInterface::class);
    $vast = new FakeVastClient([
      '100' => FakeVastClient::instance('100', 'stopped', 'exited', '198.53.64.194', '40537'),
    ]);
    $lifecycle = new FakeLifecycleClient($vast);
    $runtime = new FakeRuntimeManager($vast);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      new BadHostRegistry($state),
      1,
      0,
    );

    $results = $manager->reconcile(FALSE);
    $record = $repository->get('100');

    $this->assertCount(1, $results);
    $this->assertSame('normalized_available', $results[0]['action']);
    $this->assertNotNull($record);
    $this->assertSame('available', $record['lease_status']);
    $this->assertSame('stopped', $record['runtime_state']);
    $this->assertSame('', $record['last_error']);
  }

  /**
   * Tests reap stops an available instance but keeps it reusable.
   */
  public function testReapStopsAvailableInstanceButKeepsItReusable(): void {
    $repository = new StateMachinePoolRepository([
      '100' => [
        'contract_id' => '100',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'runtime_state' => 'running',
        'host' => '10.0.0.1',
        'port' => '22097',
        'url' => 'http://10.0.0.1:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = new FakeWorkloadCatalog();
    $state = $this->createMock(StateInterface::class);
    $vast = new FakeVastClient([
      '100' => FakeVastClient::instance('100', 'running', 'running', '198.53.64.194', '40537'),
    ]);
    $lifecycle = new FakeLifecycleClient($vast);
    $runtime = new FakeRuntimeManager($vast);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      new BadHostRegistry($state),
      1,
      0,
    );

    $results = $manager->reapIdleAvailableInstances(0, FALSE);
    $record = $repository->get('100');

    $this->assertCount(1, $results);
    $this->assertSame('stopped', $results[0]['action']);
    $this->assertNotNull($record);
    $this->assertSame('available', $record['lease_status']);
    $this->assertSame('stopped', $record['runtime_state']);
    $this->assertSame('idle_reap', $record['last_phase']);
    $this->assertSame('stopped', $record['last_action']);
    $this->assertSame(['100'], $lifecycle->stopCalls);

    $vast->instances['100'] = FakeVastClient::instance('100', 'stopped', 'exited', '198.53.64.194', '40537');
    $reused = $manager->acquire('qwen-vl');
    $this->assertSame('100', $reused['contract_id']);
    $this->assertSame('leased', $reused['lease_status']);
    $this->assertSame('running', $reused['runtime_state']);
    $this->assertSame(['100'], $lifecycle->startCalls);
    $this->assertSame(0, $runtime->freshProvisionCalls);
  }

  /**
   * Tests stopped instance wakes and reuses without fresh provision.
   */
  public function testStoppedInstanceWakesAndReusesWithoutFreshProvision(): void {
    $repository = new StateMachinePoolRepository([
      '100' => [
        'contract_id' => '100',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'runtime_state' => 'stopped',
        'host' => '10.0.0.1',
        'port' => '22097',
        'url' => 'http://10.0.0.1:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = new FakeWorkloadCatalog();
    $state = $this->createMock(StateInterface::class);
    $vast = new FakeVastClient([
      '100' => FakeVastClient::instance('100', 'stopped', 'exited', '198.53.64.194', '40537'),
    ]);
    $lifecycle = new FakeLifecycleClient($vast);
    $runtime = new FakeRuntimeManager($vast);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      new BadHostRegistry($state),
      1,
      0,
    );

    $record = $manager->acquire('qwen-vl');

    $this->assertSame('100', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame('running', $record['runtime_state']);
    $this->assertSame(0, $runtime->freshProvisionCalls);
    $this->assertSame(['100'], $lifecycle->startCalls);
    $this->assertSame([], $vast->destroyCalls);
  }

  /**
   * Tests external-lease fallback from a stopped reusable instance.
   */
  public function testStoppedInstanceQueuedAsExternalLeaseStopsAndFallsBackFresh(): void {
    $repository = new StateMachinePoolRepository([
      '100' => [
        'contract_id' => '100',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'whisper',
        'current_model' => 'openai/whisper-large-v3-turbo',
        'lease_status' => 'available',
        'runtime_state' => 'stopped',
        'host' => '198.53.64.194',
        'port' => '40537',
        'url' => 'http://198.53.64.194:40537',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = new FakeWorkloadCatalog();
    $state = $this->createMock(StateInterface::class);
    $vast = new FakeVastClient([
      '100' => FakeVastClient::instance('100', 'stopped', 'exited', '198.53.64.194', '40537'),
    ]);
    $lifecycle = new FakeLifecycleClient($vast);
    $lifecycle->startResponses['100'] = [
      'success' => FALSE,
      'error' => 'resources_unavailable',
      'msg' => 'Required resources are currently unavailable, state change queued.',
    ];
    $runtime = new FakeRuntimeManager($vast);
    $runtime->freshProvisionResponse = [
      'contract_id' => '999',
      'instance_info' => FakeVastClient::instance('999', 'running', 'running', '203.0.113.10', '40800'),
    ];

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      new BadHostRegistry($state),
      2,
      0,
    );

    $record = $manager->acquire('qwen-vl');
    $stale = $repository->get('100');
    $fresh = $repository->get('999');

    $this->assertSame('999', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame(1, $runtime->freshProvisionCalls);
    $this->assertSame(['100'], $lifecycle->startCalls);
    $this->assertSame(['100'], $lifecycle->stopCalls);
    $this->assertNotNull($stale);
    $this->assertSame('available', $stale['lease_status']);
    $this->assertSame('stopped', $stale['runtime_state']);
    $this->assertStringContainsString('state change queued', $stale['last_error']);
    $this->assertNotNull($fresh);
    $this->assertSame('fresh_fallback', $fresh['source']);
    $this->assertSame('leased', $fresh['lease_status']);
  }

  /**
   * Tests bad fresh bootstrap host is destroyed, recorded bad, and retried.
   */
  public function testBadBootstrapFreshInstanceIsDestroyedAndRetried(): void {
    $repository = new StateMachinePoolRepository([]);

    $catalog = new FakeWorkloadCatalog();
    $state = new StateMachineState([
      'compute_orchestrator.global_bad_hosts' => [],
      'compute_orchestrator.workload_bad_hosts' => [],
    ]);
    $vast = new FakeVastClient([]);
    $lifecycle = new FakeLifecycleClient($vast);
    $runtime = new FakeRuntimeManager($vast);
    $runtime->freshProvisionQueue = [
      [
        'contract_id' => '200',
        'instance_info' => FakeVastClient::instance('200', 'running', 'running', '198.53.64.200', '40600', '349045'),
      ],
      [
        'contract_id' => '201',
        'instance_info' => FakeVastClient::instance('201', 'running', 'running', '198.53.64.201', '40601', '349046'),
      ],
    ];
    $runtime->bootstrapFailures['200'] = 'kex_exchange_identification: Connection closed by remote host';
    $badHosts = new BadHostRegistry($state);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      $badHosts,
      1,
      0,
    );

    $record = $manager->acquire('whisper');

    $this->assertSame('201', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame(2, $runtime->freshProvisionCalls);
    $this->assertSame(['200'], $lifecycle->stopCalls);
    $this->assertSame(['200'], $vast->destroyCalls);
    $this->assertSame(['349045'], $badHosts->all());
    $this->assertSame(['349045'], $state->get('compute_orchestrator.global_bad_hosts', []));
    $this->assertSame(['349045'], $state->get('compute_orchestrator.workload_bad_hosts', [])['whisper'] ?? []);
    $failed = $repository->get('200');
    $this->assertNotNull($failed);
    $this->assertSame('destroyed', $failed['lease_status']);
    $this->assertSame('349045', $failed['bad_host_id']);
    $this->assertNotNull($repository->get('201'));
  }

  /**
   * Tests stopped wake failure aborts without fresh fallback.
   */
  public function testStoppedWakeFailureAbortsWithoutFreshFallback(): void {
    $repository = new StateMachinePoolRepository([
      '100' => [
        'contract_id' => '100',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'runtime_state' => 'stopped',
        'host' => '10.0.0.1',
        'port' => '22097',
        'url' => 'http://10.0.0.1:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = new FakeWorkloadCatalog();
    $state = $this->createMock(StateInterface::class);
    $vast = new FakeVastClient([
      '100' => FakeVastClient::instance('100', 'stopped', 'exited', '198.53.64.194', '40537'),
    ]);
    $lifecycle = new FakeLifecycleClient($vast);
    $runtime = new FakeRuntimeManager($vast);
    $runtime->bootstrapFailures['100'] = 'simulated bootstrap failure';
    $runtime->freshProvisionResponse = [
      'contract_id' => '999',
      'instance_info' => FakeVastClient::instance('999', 'running', 'running', '203.0.113.10', '40800'),
    ];

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      new BadHostRegistry($state),
      1,
      0,
    );

    $record = $manager->acquire('qwen-vl');
    $failed = $repository->get('100');

    $this->assertSame('999', $record['contract_id']);
    $this->assertSame(1, $runtime->freshProvisionCalls);
    $this->assertSame(['100'], $lifecycle->startCalls);
    $this->assertSame(['100'], $lifecycle->stopCalls);
    $this->assertSame(['100'], $vast->destroyCalls);
    $this->assertNotNull($failed);
    $this->assertSame('destroyed', $failed['lease_status']);
    $this->assertStringContainsString('simulated bootstrap failure', $failed['last_error']);
  }

  /**
   * Tests bootstrapping reclassification after terminal failure.
   */
  public function testBootstrappingInstanceIsReclassifiedWhenLaterPollSeesTerminalContainerFailure(): void {
    $repository = new StateMachinePoolRepository([
      '100' => [
        'contract_id' => '100',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'runtime_state' => 'stopped',
        'host' => '10.0.0.1',
        'port' => '22097',
        'url' => 'http://10.0.0.1:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = new FakeWorkloadCatalog();
    $state = $this->createMock(StateInterface::class);
    $vast = new FakeVastClient([
      '100' => FakeVastClient::instance('100', 'stopped', 'exited', '198.53.64.194', '40537'),
    ]);
    $lifecycle = new FakeLifecycleClient($vast);
    $runtime = new FakeRuntimeManager($vast);
    $runtime->pendingBootstrapCounts['100'] = 1;

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtime,
      $lifecycle,
      $vast,
      $state,
      new BadHostRegistry($state),
      1,
      0,
    );

    try {
      $manager->acquire('qwen-vl');
      $this->fail('Expected first acquire to report pending bootstrap.');
    }
    catch (AcquirePendingException) {
      // Expected: first slice leaves the record in bootstrapping state.
    }

    $afterPending = $repository->get('100');
    $this->assertNotNull($afterPending);
    $this->assertSame('bootstrapping', $afterPending['lease_status']);
    $this->assertStringContainsString(
      'SSH not ready within this polling slice',
      (string) $afterPending['last_error'],
    );

    $vast->instances['100']['cur_state'] = 'stopped';
    $vast->instances['100']['actual_status'] = 'created';
    $vast->instances['100']['status_msg'] = 'Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: could not apply required modification to OCI specification: error modifying OCI spec: failed to inject CDI devices: unresolvable CDI devices D.fake/gpu=3
failed to start containers: C.100';
    $runtime->bootstrapFromStatusMessage['100'] = TRUE;
    $runtime->freshProvisionResponse = [
      'contract_id' => '999',
      'instance_info' => FakeVastClient::instance('999', 'running', 'running', '203.0.113.10', '40800'),
    ];

    $record = $manager->acquire('qwen-vl');
    $failed = $repository->get('100');

    $this->assertSame('999', $record['contract_id']);
    $this->assertSame(1, $runtime->freshProvisionCalls);
    $this->assertSame(['100', '100'], $lifecycle->startCalls);
    $this->assertSame(['100'], $lifecycle->stopCalls);
    $this->assertSame(['100'], $vast->destroyCalls);
    $this->assertNotNull($failed);
    $this->assertSame('destroyed', $failed['lease_status']);
    $this->assertStringContainsString('failed to start containers', $failed['last_error']);
  }

}

/**
 * In-memory pool repository for state-machine tests.
 */
final class StateMachinePoolRepository implements VllmPoolRepositoryInterface {

  /**
   * Creates the in-memory pool repository.
   *
   * @param array<string, array<string,mixed>> $records
   *   Initial records keyed by contract.
   */
  public function __construct(
    private array $records,
  ) {}

  /**
   * Provides all.
   */
  public function all(): array {
    return $this->records;
  }

  /**
   * Provides get.
   */
  public function get(string $contractId): ?array {
    $record = $this->records[$contractId] ?? NULL;
    return is_array($record) ? $record : NULL;
  }

  /**
   * Provides save.
   */
  public function save(array $record): void {
    $this->records[(string) ($record['contract_id'] ?? '')] = $record;
  }

  /**
   * Provides delete.
   */
  public function delete(string $contractId): void {
    unset($this->records[$contractId]);
  }

  /**
   * Provides clear.
   */
  public function clear(): void {
    $this->records = [];
  }

}

/**
 * Fake workload catalog with deterministic qwen definition.
 */
final class FakeWorkloadCatalog implements VllmWorkloadCatalogInterface {

  /**
   * Provides get default generic image.
   */
  public function getDefaultGenericImage(): string {
    return 'thursdaybw/vllm-generic:2026-04-generic-node';
  }

  /**
   * Provides get definition.
   */
  public function getDefinition(string $workload, ?string $modelOverride = NULL): array {
    return [
      'mode' => $workload,
      'model' => $modelOverride ?? 'Qwen/Qwen2-VL-7B-Instruct',
      'gpu_ram_gte' => 20,
      'max_model_len' => 16384,
    ];
  }

}

/**
 * Fake Vast REST API with deterministic in-memory instance states.
 */
final class FakeVastClient implements VastRestClientInterface {

  /**
   * Creates the fake Vast client.
   *
   * @param array<string, array<string,mixed>> $instances
   *   Fake instances keyed by contract.
   */
  public function __construct(
    public array $instances,
  ) {}

  /**
   * Destroy calls captured for assertions.
   *
   * @var string[]
   */
  public array $destroyCalls = [];

  /**
   * Creates a normalized fake instance payload.
   *
   * @return array<string,mixed>
   *   Vast-like instance payload.
   */
  public static function instance(
    string $id,
    string $curState,
    string $actualStatus,
    string $ip,
    string $hostPort,
    string $hostId = '',
  ): array {
    return [
      'id' => $id,
      'host_id' => $hostId,
      'cur_state' => $curState,
      'actual_status' => $actualStatus,
      'public_ipaddr' => $ip,
      'ports' => [
        '8000/tcp' => [
          ['HostPort' => $hostPort],
        ],
      ],
    ];
  }

  /**
   * Provides search offers.
   */
  public function searchOffers(string $query, int $limit = 20): array {
    return [];
  }

  /**
   * Provides create instance.
   */
  public function createInstance(string $offerId, string $image, array $options = []): array {
    return [];
  }

  /**
   * Provides start instance.
   */
  public function startInstance(string $instanceId): array {
    return [];
  }

  /**
   * Provides show instance.
   */
  public function showInstance(string $instanceId): array {
    if (!isset($this->instances[$instanceId])) {
      throw new \RuntimeException('instance missing: ' . $instanceId);
    }
    return $this->instances[$instanceId];
  }

  /**
   * Provides destroy instance.
   */
  public function destroyInstance(string $instanceId): array {
    $this->destroyCalls[] = $instanceId;
    unset($this->instances[$instanceId]);
    return ['success' => TRUE];
  }

  /**
   * Provides get instance logs.
   */
  public function getInstanceLogs(string $instanceId, bool $extra = FALSE): array {
    return [];
  }

  /**
   * Provides search offers structured.
   */
  public function searchOffersStructured(array $filters, int $limit = 20): array {
    return [];
  }

  /**
   * Provides select best offer.
   */
  public function selectBestOffer(
    array $filters,
    array $excludeHostIds = [],
    array $excludeRegions = [],
    int $limit = 20,
  ): ?array {
    return NULL;
  }

  /**
   * Provides provision instance from offers.
   */
  public function provisionInstanceFromOffers(
    array $filters,
    array $excludeRegions = [],
    int $limit = 5,
    ?float $maxPrice = NULL,
    ?float $minPrice = NULL,
    array $createOptions = [],
    int $maxAttempts = 5,
    int $bootTimeoutSeconds = 600,
  ): array {
    return [];
  }

  /**
   * Provides wait for running and ssh.
   */
  public function waitForRunningAndSsh(string $instanceId, string $workload = 'vllm', int $timeoutSeconds = 180): array {
    return [];
  }

}

/**
 * Fake Vast lifecycle transitions.
 */
final class FakeLifecycleClient implements VastInstanceLifecycleClientInterface {

  /**
   * Stores start calls in order.
   *
   * @var string[]
   *   Start calls in order.
   */
  public array $startCalls = [];

  /**
   * Stores stop calls in order.
   *
   * @var string[]
   *   Stop calls in order.
   */
  public array $stopCalls = [];

  /**
   * Start responses keyed by contract ID.
   *
   * @var array<string,array<string,mixed>>
   */
  public array $startResponses = [];

  public function __construct(
    private readonly FakeVastClient $vast,
  ) {}

  /**
   * Provides start instance.
   */
  public function startInstance(string $instanceId): array {
    $this->startCalls[] = $instanceId;
    if (!isset($this->vast->instances[$instanceId])) {
      throw new \RuntimeException('instance missing: ' . $instanceId);
    }
    if (isset($this->startResponses[$instanceId])) {
      return $this->startResponses[$instanceId];
    }
    $this->vast->instances[$instanceId]['cur_state'] = 'running';
    $this->vast->instances[$instanceId]['actual_status'] = 'loading';
    return ['success' => TRUE];
  }

  /**
   * Provides stop instance.
   */
  public function stopInstance(string $instanceId): array {
    $this->stopCalls[] = $instanceId;
    if (isset($this->vast->instances[$instanceId])) {
      $this->vast->instances[$instanceId]['cur_state'] = 'stopped';
      $this->vast->instances[$instanceId]['actual_status'] = 'exited';
    }
    return ['success' => TRUE];
  }

}

/**
 * Fake runtime manager with deterministic behavior controls.
 */
final class FakeRuntimeManager implements GenericVllmRuntimeManagerInterface {

  /**
   * Number of fresh provision attempts.
   */
  public int $freshProvisionCalls = 0;

  /**
   * Fresh provision payload to return.
   *
   * @var array<string,mixed>
   */
  public array $freshProvisionResponse = [];

  /**
   * Queue of fresh provision payloads to return in order.
   *
   * @var array<int,array<string,mixed>>
   */
  public array $freshProvisionQueue = [];

  /**
   * Contract IDs that should fail bootstrap.
   *
   * @var array<string,string>
   */
  public array $bootstrapFailures = [];

  /**
   * Contract IDs that should remain pending for N bootstrap attempts.
   *
   * /**
   *
   * @var array<string,int>
   *   Pending bootstrap poll counts keyed by contract id.
   */
  public array $pendingBootstrapCounts = [];

  /**
   * Stores bootstrap-failure contract IDs from Vast status messages.
   *
   * @var array<string,bool>
   */
  public array $bootstrapFromStatusMessage = [];

  public function __construct(
    private readonly FakeVastClient $vast,
  ) {}

  /**
   * Provides provision fresh.
   */
  public function provisionFresh(array $workloadDefinition, string $image): array {
    $this->freshProvisionCalls++;
    if ($this->freshProvisionQueue !== []) {
      $next = array_shift($this->freshProvisionQueue);
      $contractId = (string) ($next['contract_id'] ?? '');
      $instanceInfo = (array) ($next['instance_info'] ?? []);
      if ($contractId !== '' && $instanceInfo !== []) {
        $this->vast->instances[$contractId] = $instanceInfo;
      }
      return $next;
    }
    if ($this->freshProvisionResponse !== []) {
      $contractId = (string) ($this->freshProvisionResponse['contract_id'] ?? '');
      $instanceInfo = (array) ($this->freshProvisionResponse['instance_info'] ?? []);
      if ($contractId !== '' && $instanceInfo !== []) {
        $this->vast->instances[$contractId] = $instanceInfo;
      }
      return $this->freshProvisionResponse;
    }
    throw new \RuntimeException('unexpected fresh provision');
  }

  /**
   * Provides wait for ssh bootstrap.
   */
  public function waitForSshBootstrap(string $contractId, int $timeoutSeconds = 600): array {
    if (($this->pendingBootstrapCounts[$contractId] ?? 0) > 0) {
      $this->pendingBootstrapCounts[$contractId]--;
      throw new \RuntimeException('Instance exceeded SSH bootstrap timeout.');
    }

    if (($this->bootstrapFromStatusMessage[$contractId] ?? FALSE) === TRUE) {
      $info = $this->vast->showInstance($contractId);
      $statusMessage = (string) ($info['status_msg'] ?? '');
      if ($statusMessage !== '') {
        throw new \RuntimeException('Container failed during bootstrap: ' . $statusMessage);
      }
    }

    if (isset($this->bootstrapFailures[$contractId])) {
      throw new \RuntimeException($this->bootstrapFailures[$contractId]);
    }
    return $this->vast->showInstance($contractId);
  }

  /**
   * Provides start workload.
   */
  public function startWorkload(array $instanceInfo, array $workloadDefinition): void {
    $contractId = (string) ($instanceInfo['id'] ?? '');
    if ($contractId !== '' && isset($this->vast->instances[$contractId])) {
      $this->vast->instances[$contractId]['cur_state'] = 'running';
      $this->vast->instances[$contractId]['actual_status'] = 'running';
    }
  }

  /**
   * Provides stop workload.
   */
  public function stopWorkload(array $instanceInfo): void {
  }

  /**
   * Provides wait for workload ready.
   */
  public function waitForWorkloadReady(string $contractId, int $timeoutSeconds = 900): array {
    $info = $this->vast->showInstance($contractId);
    $info['cur_state'] = 'running';
    $info['actual_status'] = 'running';
    $this->vast->instances[$contractId] = $info;
    return $info;
  }

}


/**
 * Minimal in-memory Drupal state implementation for state-machine tests.
 */
final class StateMachineState implements StateInterface {

  public function __construct(
    private array $values = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    return $this->values[$key] ?? $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    $out = [];
    foreach ($keys as $key) {
      if (array_key_exists($key, $this->values)) {
        $out[$key] = $this->values[$key];
      }
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
    $this->values[$key] = $value;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $data) {
    foreach ($data as $key => $value) {
      $this->values[$key] = $value;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key) {
    unset($this->values[$key]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $keys) {
    foreach ($keys as $key) {
      unset($this->values[$key]);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache() {
    return $this;
  }

}
