<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

use Drupal\compute_orchestrator\Service\RuntimeLeaseResponseMapper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Service/RuntimeLeaseResponseMapper.php';

/**
 * Tests external runtime lease response mapping.
 */
final class RuntimeLeaseResponseMapperTest extends TestCase {

  /**
   * Tests leased pool records map to the remote lease contract.
   */
  public function testNormalizeLeaseMapsLeasedRecord(): void {
    $mapper = new RuntimeLeaseResponseMapper();

    $lease = $mapper->normalizeLease([
      'contract_id' => '123',
      'lease_token' => 'token-123',
      'current_workload_mode' => 'whisper',
      'current_model' => 'openai/whisper-large-v3-turbo',
      'url' => 'http://1.2.3.4:22097',
      'lease_status' => 'leased',
      'runtime_state' => 'running',
      'lease_expires_at' => 1_774_000_000,
    ]);

    $this->assertSame('123', $lease['lease_id']);
    $this->assertSame('token-123', $lease['lease_token']);
    $this->assertSame('whisper', $lease['workload']);
    $this->assertSame('openai/whisper-large-v3-turbo', $lease['model']);
    $this->assertSame('http://1.2.3.4:22097', $lease['endpoint_url']);
    $this->assertSame('leased', $lease['lease_status']);
    $this->assertSame('running', $lease['runtime_state']);
    $this->assertSame('2026-03-20T09:46:40Z', $lease['expires_at']);
  }

  /**
   * Tests released pool records map to client-visible released status.
   */
  public function testNormalizeLeaseMapsAvailableRecordAsReleased(): void {
    $mapper = new RuntimeLeaseResponseMapper();

    $lease = $mapper->normalizeLease([
      'contract_id' => '123',
      'current_workload_mode' => 'qwen-vl',
      'current_model' => 'Qwen/Qwen2-VL-7B-Instruct',
      'lease_status' => 'available',
      'runtime_state' => 'stopped',
    ], FALSE);

    $this->assertSame('released', $lease['lease_status']);
    $this->assertSame('stopped', $lease['runtime_state']);
    $this->assertArrayNotHasKey('lease_token', $lease);
  }

  /**
   * Tests error payloads use the documented shape.
   */
  public function testNormalizeErrorUsesContractShape(): void {
    $mapper = new RuntimeLeaseResponseMapper();

    $payload = $mapper->normalizeError(
      'runtime_unavailable',
      'No runtime available.',
      TRUE,
      ['phase' => 'acquire'],
    );

    $this->assertSame('runtime_unavailable', $payload['error']['code']);
    $this->assertSame('No runtime available.', $payload['error']['message']);
    $this->assertTrue($payload['error']['retryable']);
    $this->assertSame('acquire', $payload['error']['diagnostics']['phase']);
  }

}
