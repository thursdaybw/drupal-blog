<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Issues start/stop lifecycle transitions against existing Vast instances.
 */
final class VastInstanceLifecycleClient implements VastInstanceLifecycleClientInterface {

  public function __construct(
    private readonly VastApiClientInterface $vastApiClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function startInstance(string $instanceId): array {
    return $this->changeState($instanceId, 'running');
  }

  /**
   * {@inheritdoc}
   */
  public function stopInstance(string $instanceId): array {
    return $this->changeState($instanceId, 'stopped');
  }

  /**
   * Requests a state transition for an instance.
   */
  private function changeState(string $instanceId, string $state): array {
    try {
      return $this->vastApiClient->request('PUT', 'instances/' . (int) $instanceId . '/', [
        'json' => [
          'state' => $state,
        ],
      ]);
    }
    catch (\RuntimeException $exception) {
      throw new \RuntimeException('Vast instance state change failed: ' . $exception->getMessage(), 0, $exception);
    }
  }

}
