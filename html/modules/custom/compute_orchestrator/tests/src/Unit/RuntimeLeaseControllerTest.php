<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

use Drupal\compute_orchestrator\Controller\RuntimeLeaseController;
use Drupal\compute_orchestrator\Service\BadHostRegistry;
use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManagerInterface;
use Drupal\compute_orchestrator\Service\RuntimeLeaseResponseMapper;
use Drupal\compute_orchestrator\Service\VastInstanceLifecycleClientInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drupal\compute_orchestrator\Service\VllmPoolRepositoryInterface;
use Drupal\compute_orchestrator\Service\VllmWorkloadCatalogInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../../../src/Controller/RuntimeLeaseController.php';
require_once __DIR__ . '/../../../src/Service/RuntimeLeaseResponseMapper.php';
require_once __DIR__ . '/../../../src/Service/VllmPoolManager.php';
require_once __DIR__ . '/../../../src/Service/BadHostRegistry.php';
require_once __DIR__ . '/../../../src/Service/VllmPoolRepositoryInterface.php';
require_once __DIR__ . '/../../../src/Service/VllmWorkloadCatalogInterface.php';
require_once __DIR__ . '/../../../src/Service/GenericVllmRuntimeManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/VastInstanceLifecycleClientInterface.php';
require_once __DIR__ . '/../../../src/Service/VastRestClientInterface.php';
require_once __DIR__ . '/../../../src/Exception/AcquirePendingException.php';
require_once __DIR__ . '/../../../src/Exception/WorkloadReadinessException.php';
require_once __DIR__ . '/../../../src/Service/Workload/FailureClass.php';

/**
 * Tests remote runtime lease controller contract behavior.
 */
final class RuntimeLeaseControllerTest extends TestCase {

  /**
   * Tests acquire validates required workload input.
   */
  public function testAcquireRequiresWorkload(): void {
    $controller = $this->newController([]);

    $response = $controller->acquire($this->jsonRequest([]));
    $payload = $this->decode($response);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('invalid_request', $payload['error']['code']);
    $this->assertSame('workload is required.', $payload['error']['message']);
  }

  /**
   * Tests inspect maps an internal pool record to the remote contract.
   */
  public function testInspectMapsLeaseResponse(): void {
    $controller = $this->newController([
      '123' => $this->leasedRecord(),
    ]);

    $response = $controller->inspect('123');
    $payload = $this->decode($response);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('123', $payload['lease']['lease_id']);
    $this->assertSame('whisper', $payload['lease']['workload']);
    $this->assertSame('leased', $payload['lease']['lease_status']);
    $this->assertSame('running', $payload['lease']['runtime_state']);
    $this->assertArrayNotHasKey('lease_token', $payload['lease']);
    $this->assertSame('lease: leased', $payload['diagnostics']['last_operation']);
  }

  /**
   * Tests renew rejects a mismatched lease token.
   */
  public function testRenewRejectsTokenMismatch(): void {
    $controller = $this->newController([
      '123' => $this->leasedRecord(),
    ]);

    $response = $controller->renew($this->jsonRequest([
      'lease_token' => 'wrong-token',
    ]), '123');
    $payload = $this->decode($response);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('lease_token_mismatch', $payload['error']['code']);
  }

  /**
   * Tests release validates token and maps the released lease response.
   */
  public function testReleaseMapsReleasedLease(): void {
    $controller = $this->newController([
      '123' => $this->leasedRecord(),
    ]);

    $response = $controller->release($this->jsonRequest([
      'lease_token' => 'token-123',
    ]), '123');
    $payload = $this->decode($response);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('123', $payload['lease']['lease_id']);
    $this->assertSame('released', $payload['lease']['lease_status']);
    $this->assertArrayNotHasKey('lease_token', $payload['lease']);
    $this->assertSame('lease: released', $payload['diagnostics']['last_operation']);
  }

  /**
   * Builds a controller with a real pool manager and in-memory repository.
   *
   * @param array<string,array<string,mixed>> $records
   *   Initial pool records.
   */
  private function newController(array $records): RuntimeLeaseController {
    $state = $this->createMock(StateInterface::class);

    $manager = new VllmPoolManager(
      new RuntimeLeaseTestPoolRepository($records),
      new RuntimeLeaseTestWorkloadCatalog(),
      $this->createMock(GenericVllmRuntimeManagerInterface::class),
      $this->createMock(VastInstanceLifecycleClientInterface::class),
      $this->createMock(VastRestClientInterface::class),
      $state,
      new BadHostRegistry($state),
      3,
      0,
    );

    return new RuntimeLeaseController($manager, new RuntimeLeaseResponseMapper());
  }

  /**
   * Builds a JSON request.
   *
   * @param array<string,mixed> $payload
   *   Request payload.
   */
  private function jsonRequest(array $payload): Request {
    return Request::create(
      '/api/compute-orchestrator/runtime-leases',
      'POST',
      [],
      [],
      [],
      [],
      json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string,mixed>
   *   Decoded response payload.
   */
  private function decode(Response $response): array {
    return json_decode($response->getContent() ?: '{}', TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Returns a leased pool record fixture.
   *
   * @return array<string,mixed>
   *   Pool record.
   */
  private function leasedRecord(): array {
    return [
      'contract_id' => '123',
      'image' => 'thursdaybw/vllm-generic:2026-04-generic-node',
      'current_workload_mode' => 'whisper',
      'current_model' => 'openai/whisper-large-v3-turbo',
      'lease_status' => 'leased',
      'runtime_state' => 'running',
      'lease_token' => 'token-123',
      'leased_at' => 1_774_000_000,
      'last_heartbeat_at' => 1_774_000_000,
      'lease_expires_at' => 1_774_000_900,
      'host' => '1.2.3.4',
      'port' => '22097',
      'url' => 'http://1.2.3.4:22097',
      'source' => 'unit_test',
      'last_phase' => 'lease',
      'last_action' => 'leased',
      'last_error' => '',
      'last_seen_at' => 1_774_000_000,
      'last_used_at' => 1_774_000_000,
    ];
  }

}

/**
 * In-memory pool repository for runtime lease controller tests.
 */
final class RuntimeLeaseTestPoolRepository implements VllmPoolRepositoryInterface {

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
 * Minimal workload catalog fixture for runtime lease controller tests.
 */
final class RuntimeLeaseTestWorkloadCatalog implements VllmWorkloadCatalogInterface {

  /**
   * {@inheritdoc}
   */
  public function getDefaultGenericImage(): string {
    return 'thursdaybw/vllm-generic:2026-04-generic-node';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition(string $workload, ?string $modelOverride = NULL): array {
    return [
      'mode' => $workload,
      'model' => $modelOverride ?: 'unit-test-model',
      'gpu_ram_gte' => 1,
    ];
  }

}
