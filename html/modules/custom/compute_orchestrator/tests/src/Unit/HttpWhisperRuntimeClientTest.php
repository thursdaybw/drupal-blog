<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

use Drupal\media_transcription\Service\HttpWhisperRuntimeClient;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../../../media_transcription/src/Service/WhisperRuntimeClientInterface.php';
require_once __DIR__ . '/../../../../media_transcription/src/Service/HttpWhisperRuntimeClient.php';

/**
 * Tests the remote HTTP Framesmith compute runtime client.
 */
final class HttpWhisperRuntimeClientTest extends TestCase {

  /**
   * Tests acquire calls the remote runtime lease API and maps the lease shape.
   */
  public function testAcquireWhisperRuntimeRequestsRemoteLease(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $client = new HttpWhisperRuntimeClient(
      $httpClient,
      $this->configuredState(),
      new NullLogger(),
    );

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://compute.example.test/api/compute-orchestrator/runtime-leases',
        $this->callback(function (array $options): bool {
          TestCase::assertSame('Bearer test-access-token', $options['headers']['Authorization'] ?? NULL);
          TestCase::assertSame('application/json', $options['headers']['Accept'] ?? NULL);
          TestCase::assertSame('whisper', $options['json']['workload'] ?? NULL);
          TestCase::assertSame('media_transcription', $options['json']['client'] ?? NULL);
          TestCase::assertSame('transcription', $options['json']['purpose'] ?? NULL);
          TestCase::assertTrue($options['json']['allow_provision'] ?? FALSE);
          return TRUE;
        }),
      )
      ->willReturn(new Response(200, [], json_encode([
        'lease' => [
          'lease_id' => 'vast:123',
          'lease_token' => 'lease-token-123',
          'workload' => 'whisper',
          'model' => 'openai/whisper-large-v3-turbo',
          'endpoint_url' => 'http://10.0.0.4:22097',
          'lease_status' => 'leased',
          'runtime_state' => 'running',
        ],
      ], JSON_THROW_ON_ERROR)));

    $lease = $client->acquireWhisperRuntime();

    $this->assertSame('123', $lease['contract_id']);
    $this->assertSame('lease-token-123', $lease['lease_token']);
    $this->assertSame('10.0.0.4', $lease['host']);
    $this->assertSame('22097', $lease['port']);
    $this->assertSame('http://10.0.0.4:22097', $lease['url']);
    $this->assertSame('whisper', $lease['current_workload_mode']);
    $this->assertSame('openai/whisper-large-v3-turbo', $lease['current_model']);
  }

  /**
   * Tests remote release requires the backend-owned lease token.
   */
  public function testReleaseRuntimeRequiresLeaseToken(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->never())->method('request');
    $client = new HttpWhisperRuntimeClient(
      $httpClient,
      $this->configuredState(),
      new NullLogger(),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Cannot release remote Whisper runtime lease without a lease token.');

    $client->releaseRuntime('123');
  }

  /**
   * Tests release calls the remote runtime lease API with the lease token.
   */
  public function testReleaseRuntimeCallsRemoteReleaseEndpoint(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $client = new HttpWhisperRuntimeClient(
      $httpClient,
      $this->configuredState(),
      new NullLogger(),
    );

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://compute.example.test/api/compute-orchestrator/runtime-leases/123/release',
        $this->callback(function (array $options): bool {
          TestCase::assertSame('Bearer test-access-token', $options['headers']['Authorization'] ?? NULL);
          TestCase::assertSame('lease-token-123', $options['json']['lease_token'] ?? NULL);
          TestCase::assertSame('transcription task finished', $options['json']['reason'] ?? NULL);
          return TRUE;
        }),
      )
      ->willReturn(new Response(200, [], json_encode([
        'lease' => [
          'lease_id' => '123',
          'workload' => 'whisper',
          'model' => 'openai/whisper-large-v3-turbo',
          'endpoint_url' => 'http://10.0.0.4:22097',
          'lease_status' => 'released',
          'runtime_state' => 'stopped',
        ],
      ], JSON_THROW_ON_ERROR)));

    $lease = $client->releaseRuntime('123', 'lease-token-123');

    $this->assertSame('123', $lease['contract_id']);
    $this->assertSame('http://10.0.0.4:22097', $lease['url']);
    $this->assertSame('', $lease['lease_token']);
  }

  /**
   * Builds configured state for HTTP client tests.
   */
  private function configuredState(): StateInterface {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
      return match ($key) {
        HttpWhisperRuntimeClient::STATE_BASE_URL => 'https://compute.example.test/',
        HttpWhisperRuntimeClient::STATE_ACCESS_TOKEN => 'test-access-token',
        default => $default,
      };
    });
    return $state;
  }

}
