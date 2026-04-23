<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Exception/AcquirePendingException.php';
require_once __DIR__ . '/../../../src/Exception/WorkloadReadinessException.php';
require_once __DIR__ . '/../../../src/Service/Workload/FailureClass.php';
require_once __DIR__ . '/../../../src/Service/VllmPoolManager.php';
require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/VastInstanceLifecycleClientInterface.php';
require_once __DIR__ . '/../../../src/Service/VastRestClientInterface.php';
require_once __DIR__ . '/../../../src/Service/VllmPoolRepositoryInterface.php';
require_once __DIR__ . '/../../../src/Service/VllmWorkloadCatalogInterface.php';

use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManagerInterface;
use Drupal\compute_orchestrator\Service\VastInstanceLifecycleClientInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drupal\compute_orchestrator\Service\VllmPoolRepositoryInterface;
use Drupal\compute_orchestrator\Service\VllmWorkloadCatalogInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pooled acquire decision tree for generic vLLM instances.
 */
final class VllmPoolManagerTest extends TestCase {

  /**
   * Tests register instance stores arbitrary leased contract for pool testing.
   */
  public function testRegisterInstanceStoresArbitraryLeasedContractForPoolTesting(): void {
    $repository = $this->newInMemoryRepository([]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('34414828')
      ->willReturn([
        'id' => '34414828',
        'cur_state' => 'stopped',
        'actual_status' => 'stopped',
        'public_ipaddr' => '194.14.47.19',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '22097'],
          ],
        ],
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $record = $manager->registerInstance(
      '34414828',
      'thursdaybw/vllm-generic:2026-04-generic-node',
      '',
      '',
      'manual',
    );

    $this->assertSame('34414828', $record['contract_id']);
    $this->assertSame('available', $record['lease_status']);
    $this->assertSame('194.14.47.19', $record['host']);
    $this->assertSame('22097', $record['port']);
    $this->assertSame('http://194.14.47.19:22097', $record['url']);
  }

  /**
   * Tests acquire reuses running matching instance before fresh provision.
   */
  public function testAcquireReusesRunningMatchingInstanceBeforeFreshProvision(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '1.2.3.4',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '22097'],
          ],
        ],
      ]);
    $runtimeManager->expects($this->never())
      ->method('provisionFresh');
    $runtimeManager->expects($this->never())
      ->method('startWorkload');
    $runtimeManager->expects($this->never())
      ->method('stopWorkload');

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->never())
      ->method('startInstance');

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '1.2.3.4',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '22097'],
          ],
        ],
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');

    $this->assertSame('123', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame('qwen-vl', $record['current_workload_mode']);
    $this->assertSame(
      'Qwen/Qwen2-VL-7B-Instruct',
      $record['current_model'],
    );
    $this->assertSame('http://1.2.3.4:22097', $record['url']);
  }

  /**
   * Tests acquire skips external sleeping instances and provisions fresh.
   */
  public function testAcquireSkipsExternallyRentedSleepingInstanceAndFallsBackToFreshProvision(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'whisper',
        'current_model' => 'openai/whisper-large-v3-turbo',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '5.6.7.8',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '23001'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->once())
      ->method('startInstance')
      ->with('123');
    $lifecycleClient->expects($this->once())
      ->method('stopInstance')
      ->with('123');

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->exactly(4))
      ->method('showInstance')
      ->with('123')
      ->willReturnOnConsecutiveCalls(
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'stopped',
        ],
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'scheduling',
        ],
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'scheduling',
        ],
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'scheduling',
        ],
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'scheduling',
        ],
      );

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');
    $records = $repository->all();

    $this->assertSame('999', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame('rented_elsewhere', $records['123']['lease_status']);
    $this->assertSame('fresh_fallback', $records['999']['source']);
  }

  /**
   * Tests acquire marks queued wake as rented elsewhere.
   */
  public function testAcquireMarksQueuedWakeAsRentedElsewhere(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => '',
        'current_model' => '',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->never())
      ->method('waitForSshBootstrap');

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->once())
      ->method('startInstance')
      ->with('123')
      ->willReturn([
        'success' => FALSE,
        'error' => 'resources_unavailable',
        'msg' => 'Required resources are currently unavailable, state change queued.',
      ]);
    $lifecycleClient->expects($this->never())
      ->method('stopInstance');

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'stopped',
        'actual_status' => 'exited',
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No pooled instances available. Fresh provisioning is disabled.');
    try {
      $manager->acquire('qwen-vl', NULL, FALSE);
    }
    finally {
      $record = $repository->get('123');
      $this->assertNotNull($record);
      $this->assertSame('rented_elsewhere', $record['lease_status']);
      $this->assertSame('Required resources are currently unavailable, state change queued.', $record['last_error']);
    }
  }

  /**
   * Tests acquire switches running instance to requested workload.
   */
  public function testAcquireSwitchesRunningInstanceToRequestedWorkload(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'whisper',
        'current_model' => 'openai/whisper-large-v3-turbo',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('stopWorkload');
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '1.2.3.4',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '22097'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->never())
      ->method('startInstance');

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '1.2.3.4',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '22097'],
          ],
        ],
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');

    $this->assertSame('123', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame('qwen-vl', $record['current_workload_mode']);
    $this->assertSame(
      'Qwen/Qwen2-VL-7B-Instruct',
      $record['current_model'],
    );
  }

  /**
   * Tests acquire falls back to fresh after wake rate limit.
   */
  public function testAcquireFallsBackToFreshAfterWakeRateLimit(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => '',
        'current_model' => '',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '5.6.7.8',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '23001'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->once())
      ->method('startInstance')
      ->with('123')
      ->willThrowException(new \RuntimeException(
        'Vast instance state change failed: {"message":"API requests too frequent","code":"429 Too Many Requests"}'
      ));
    $lifecycleClient->expects($this->never())
      ->method('stopInstance');

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->exactly(2))
      ->method('showInstance')
      ->with('123')
      ->willReturnOnConsecutiveCalls(
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'stopped',
        ],
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'stopped',
        ],
        [
          'id' => '123',
          'cur_state' => 'stopped',
          'actual_status' => 'stopped',
        ],
      );

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');
    $records = $repository->all();

    $this->assertSame('999', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame('unavailable', $records['123']['lease_status']);
    $this->assertStringContainsString('429 Too Many Requests', (string) $records['123']['last_error']);
  }

  /**
   * Tests acquire scales out when only matching instance is leased.
   */
  public function testAcquireScalesOutWhenOnlyMatchingInstanceIsLeased(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'leased',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
        'lease_token' => 'abc',
        'leased_at' => time(),
        'last_heartbeat_at' => time(),
        'lease_expires_at' => time() + 600,
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '5.6.7.8',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '23001'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->never())
      ->method('showInstance');

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');

    $this->assertSame('999', $record['contract_id']);
    $this->assertSame('leased', $record['lease_status']);
    $this->assertSame('leased', $repository->get('123')['lease_status']);
  }

  /**
   * Tests acquire refuses scale out when matching pool max is reached.
   */
  public function testAcquireRefusesScaleOutWhenMatchingPoolMaxIsReached(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'leased',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
        'lease_token' => 'abc',
        'leased_at' => time(),
        'last_heartbeat_at' => time(),
        'lease_expires_at' => time() + 600,
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
        if ($key === 'compute_orchestrator.vllm_pool.max_instances_per_workload') {
          return 1;
        }
        return $default;
      });

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $state,
      3,
      0,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No pooled capacity available for qwen-vl: matching pool size limit 1 reached.');
    $manager->acquire('qwen-vl');
  }

  /**
   * Tests acquire scale out counts only matching runtime profile.
   */
  public function testAcquireScaleOutCountsOnlyMatchingRuntimeProfile(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'leased',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
        'lease_token' => 'abc',
        'leased_at' => time(),
        'last_heartbeat_at' => time(),
        'lease_expires_at' => time() + 600,
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('whisper', NULL)
      ->willReturn([
        'mode' => 'whisper',
        'model' => 'openai/whisper-large-v3-turbo',
        'gpu_ram_gte' => 16,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '5.6.7.8',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '23001'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
        if ($key === 'compute_orchestrator.vllm_pool.max_instances_per_workload') {
          return 1;
        }
        return $default;
      });

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $state,
      3,
      0,
    );

    $record = $manager->acquire('whisper');
    $this->assertSame('999', $record['contract_id']);
    $this->assertSame('whisper', $record['current_workload_mode']);
  }

  /**
   * Tests acquire unlimited pool size allows scale out.
   */
  public function testAcquireUnlimitedPoolSizeAllowsScaleOut(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'leased',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
        'lease_token' => 'abc',
        'leased_at' => time(),
        'last_heartbeat_at' => time(),
        'lease_expires_at' => time() + 600,
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '5.6.7.8',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '23001'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
        if ($key === 'compute_orchestrator.vllm_pool.max_instances_per_workload') {
          return 0;
        }
        return $default;
      });

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $state,
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');
    $this->assertSame('999', $record['contract_id']);
  }

  /**
   * Tests acquire does not count unavailable member against pool limit.
   */
  public function testAcquireDoesNotCountUnavailableMemberAgainstPoolLimit(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'unavailable',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => 'bad node',
      ],
    ]);

    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload');
    $runtimeManager->expects($this->once())
      ->method('waitForWorkloadReady')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'running',
        'actual_status' => 'running',
        'public_ipaddr' => '5.6.7.8',
        'ports' => [
          '8000/tcp' => [
            ['HostPort' => '23001'],
          ],
        ],
      ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
        if ($key === 'compute_orchestrator.vllm_pool.max_instances_per_workload') {
          return 1;
        }
        return $default;
      });

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $state,
      3,
      0,
    );

    $record = $manager->acquire('qwen-vl');
    $this->assertSame('999', $record['contract_id']);
  }

  /**
   * Tests acquire without fresh throws when pool is empty.
   */
  public function testAcquireWithoutFreshThrowsWhenPoolIsEmpty(): void {
    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);

    $manager = new VllmPoolManager(
      $this->newInMemoryRepository([]),
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No pooled instances available. Fresh provisioning is disabled.');
    $manager->acquire('qwen-vl', NULL, FALSE);
  }

  /**
   * Tests acquire fresh failure destroys leaked contract.
   */
  public function testAcquireFreshFailureDestroysLeakedContract(): void {
    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload')
      ->willThrowException(new \RuntimeException('simulated startup failure'));
    $runtimeManager->expects($this->never())
      ->method('waitForWorkloadReady');

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('destroyInstance')
      ->with('999')
      ->willReturn(['success' => TRUE]);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('999')
      ->willThrowException(new \RuntimeException('instance missing: 999'));

    $manager = new VllmPoolManager(
      $this->newInMemoryRepository([]),
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('failed workload startup and was destroyed');
    $manager->acquire('qwen-vl');
  }

  /**
   * Tests acquire fresh failure includes cleanup failure when destroy throws.
   */
  public function testAcquireFreshFailureIncludesCleanupFailureWhenDestroyThrows(): void {
    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $repository = $this->newInMemoryRepository([]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload')
      ->willThrowException(new \RuntimeException('simulated startup failure'));
    $runtimeManager->expects($this->never())
      ->method('waitForWorkloadReady');

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('destroyInstance')
      ->with('999')
      ->willThrowException(new \RuntimeException('simulated destroy failure'));

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    try {
      $manager->acquire('qwen-vl');
      $this->fail('Expected cleanup failure to be surfaced.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('simulated startup failure', $exception->getMessage());
      $this->assertStringContainsString('simulated destroy failure', $exception->getMessage());
    }

    $record = $repository->get('999');
    $this->assertNotNull($record);
    $this->assertSame('unavailable', $record['lease_status']);
    $this->assertArrayHasKey('cleanup_error', $record);
    $this->assertStringContainsString('simulated destroy failure', (string) $record['cleanup_error']);
  }

  /**
   * Tests acquire fresh failure verifies destroy actually removed contract.
   */
  public function testAcquireFreshFailureVerifiesDestroyActuallyRemovedContract(): void {
    $catalog = $this->createMock(VllmWorkloadCatalogInterface::class);
    $catalog->expects($this->once())
      ->method('getDefinition')
      ->with('qwen-vl', NULL)
      ->willReturn([
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ]);
    $catalog->expects($this->once())
      ->method('getDefaultGenericImage')
      ->willReturn('thursdaybw/vllm-generic:2026-04-generic-node');

    $repository = $this->newInMemoryRepository([]);

    $runtimeManager = $this->createMock(GenericVllmRuntimeManagerInterface::class);
    $runtimeManager->expects($this->once())
      ->method('provisionFresh')
      ->willReturn([
        'contract_id' => '999',
        'instance_info' => [
          'ssh_host' => 'ssh3.vast.ai',
          'ssh_port' => 14999,
          'ssh_user' => 'root',
        ],
      ]);
    $runtimeManager->expects($this->once())
      ->method('startWorkload')
      ->willThrowException(new \RuntimeException('simulated startup failure'));
    $runtimeManager->expects($this->never())
      ->method('waitForWorkloadReady');

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->exactly(3))
      ->method('destroyInstance')
      ->with('999')
      ->willReturn(['success' => TRUE]);
    $vastClient->expects($this->exactly(3))
      ->method('showInstance')
      ->with('999')
      ->willReturn([
        'id' => '999',
        'cur_state' => 'stopped',
        'actual_status' => 'created',
        'status_msg' => 'still present after destroy',
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $catalog,
      $runtimeManager,
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    try {
      $manager->acquire('qwen-vl');
      $this->fail('Expected destroy verification failure to be surfaced.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('simulated startup failure', $exception->getMessage());
      $this->assertStringContainsString('cleanup', strtolower($exception->getMessage()));
    }

    $record = $repository->get('999');
    $this->assertNotNull($record);
    $this->assertSame('unavailable', $record['lease_status']);
    $this->assertArrayHasKey('cleanup_status', $record);
    $this->assertSame('failed', $record['cleanup_status']);
  }

  /**
   * Tests remove and clear delete tracked inventory.
   */
  public function testRemoveAndClearDeleteTrackedInventory(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => '',
        'current_model' => '',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
      '456' => [
        'contract_id' => '456',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => '',
        'current_model' => '',
        'lease_status' => 'available',
        'host' => '',
        'port' => '',
        'url' => '',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => 1,
        'last_error' => '',
      ],
    ]);

    $manager = new VllmPoolManager(
      $repository,
      $this->createMock(VllmWorkloadCatalogInterface::class),
      $this->createMock(GenericVllmRuntimeManagerInterface::class),
      $this->createMock(VastInstanceLifecycleClientInterface::class),
      $this->createMock(VastRestClientInterface::class),
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $manager->remove('123');
    $this->assertNull($repository->get('123'));
    $this->assertNotNull($repository->get('456'));

    $manager->clear();
    $this->assertSame([], $repository->all());
  }

  /**
   * Tests reap idle available instances stops running instance past threshold.
   */
  public function testReapIdleAvailableInstancesStopsRunningInstancePastThreshold(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => time() - 601,
        'last_error' => '',
      ],
      '456' => [
        'contract_id' => '456',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'whisper',
        'current_model' => 'openai/whisper-large-v3-turbo',
        'lease_status' => 'leased',
        'host' => '5.6.7.8',
        'port' => '23001',
        'url' => 'http://5.6.7.8:23001',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => time() - 601,
        'last_error' => '',
      ],
    ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->once())
      ->method('stopInstance')
      ->with('123')
      ->willReturn(['success' => TRUE]);

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $this->createMock(VllmWorkloadCatalogInterface::class),
      $this->createMock(GenericVllmRuntimeManagerInterface::class),
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $results = $manager->reapIdleAvailableInstances(600);
    $record = $repository->get('123');

    $this->assertSame('stopped', $results[0]['action']);
    $this->assertNotNull($record);
    $this->assertSame('available', $record['lease_status']);
    $this->assertArrayHasKey('last_stopped_at', $record);
  }

  /**
   * Tests reap idle instances allows immediate reaping at zero threshold.
   */
  public function testReapIdleAvailableInstancesAllowsImmediateReapWhenThresholdIsZero(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => time(),
        'last_error' => '',
      ],
    ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->once())
      ->method('stopInstance')
      ->with('123')
      ->willReturn(['success' => TRUE]);

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $this->createMock(VllmWorkloadCatalogInterface::class),
      $this->createMock(GenericVllmRuntimeManagerInterface::class),
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $results = $manager->reapIdleAvailableInstances(0);
    $record = $repository->get('123');

    $this->assertSame('stopped', $results[0]['action']);
    $this->assertNotNull($record);
    $this->assertArrayHasKey('last_stopped_at', $record);
  }

  /**
   * Tests reap idle available instances honours dry run.
   */
  public function testReapIdleAvailableInstancesHonoursDryRun(): void {
    $repository = $this->newInMemoryRepository([
      '123' => [
        'contract_id' => '123',
        'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
        'current_workload_mode' => 'qwen-vl',
        'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'lease_status' => 'available',
        'host' => '1.2.3.4',
        'port' => '22097',
        'url' => 'http://1.2.3.4:22097',
        'source' => 'manual',
        'last_seen_at' => 1,
        'last_used_at' => time() - 601,
        'last_error' => '',
      ],
    ]);

    $lifecycleClient = $this->createMock(VastInstanceLifecycleClientInterface::class);
    $lifecycleClient->expects($this->never())
      ->method('stopInstance');

    $vastClient = $this->createMock(VastRestClientInterface::class);
    $vastClient->expects($this->once())
      ->method('showInstance')
      ->with('123')
      ->willReturn([
        'id' => '123',
        'cur_state' => 'running',
        'actual_status' => 'running',
      ]);

    $manager = new VllmPoolManager(
      $repository,
      $this->createMock(VllmWorkloadCatalogInterface::class),
      $this->createMock(GenericVllmRuntimeManagerInterface::class),
      $lifecycleClient,
      $vastClient,
      $this->createMock(StateInterface::class),
      3,
      0,
    );

    $results = $manager->reapIdleAvailableInstances(600, TRUE);
    $record = $repository->get('123');

    $this->assertSame('would_stop', $results[0]['action']);
    $this->assertNotNull($record);
    $this->assertArrayNotHasKey('last_stopped_at', $record);
  }

  /**
   * Builds an in-memory pool repository for unit tests.
   *
   * @param array<string, array<string,mixed>> $records
   *   Initial in-memory records keyed by contract ID.
   */
  private function newInMemoryRepository(array $records): VllmPoolRepositoryInterface {
    return new class($records) implements VllmPoolRepositoryInterface {

      /**
       * Constructs the repository.
       *
       * @param array<string, array<string,mixed>> $records
       *   Initial in-memory records keyed by contract ID.
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
        $record = $this->records[$contractId] ?? NULL;
        return is_array($record) ? $record : NULL;
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

    };
  }

}
