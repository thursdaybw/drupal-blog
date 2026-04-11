<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drush\Commands\DrushCommands;

/**
 * Provides operator commands for the generic vLLM instance pool.
 */
final class VllmInstancePoolCommand extends DrushCommands {

  public function __construct(
    private readonly VllmPoolManager $poolManager,
  ) {
    parent::__construct();
  }

  /**
   * Registers an arbitrary leased Vast contract as a pooled instance.
   *
   * @param string $instanceId
   *   Vast contract ID to track in the pool inventory.
   * @param array<string,mixed> $options
   *   Command options keyed by image, workload, model, and source.
   *
   * @command compute:vllm-pool-register
   * @option image
   *   Generic runtime image reference.
   * @option workload
   *   Optional current workload mode already loaded on the instance.
   * @option model
   *   Optional current model already loaded on the instance.
   * @option source
   *   Free-form source tag for operator visibility.
   */
  public function register(
    string $instanceId,
    array $options = [
      'image' => '',
      'workload' => '',
      'model' => '',
      'source' => 'manual',
    ],
  ): void {
    $record = $this->poolManager->registerInstance(
      $instanceId,
      (string) ($options['image'] ?? ''),
      (string) ($options['workload'] ?? ''),
      (string) ($options['model'] ?? ''),
      (string) ($options['source'] ?? 'manual'),
    );
    $this->output()->writeln('Registered pooled instance ' . (string) $record['contract_id'] . '.');
  }

  /**
   * Lists the current state-backed pool inventory.
   *
   * @command compute:vllm-pool-list
   */
  public function list(): void {
    $instances = $this->poolManager->listInstances();
    if ($instances === []) {
      $this->output()->writeln('No pooled instances are registered.');
      return;
    }

    foreach ($instances as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }

      $this->output()->writeln(sprintf(
        '%s status=%s workload=%s model=%s url=%s source=%s',
        $contractId,
        (string) ($record['lease_status'] ?? ''),
        (string) ($record['current_workload_mode'] ?? ''),
        (string) ($record['current_model'] ?? ''),
        (string) ($record['url'] ?? ''),
        (string) ($record['source'] ?? '')
      ));
    }
  }

  /**
   * Acquires a pooled instance for the requested workload.
   *
   * @param array<string,mixed> $options
   *   Command options keyed by workload, model, and no-fresh.
   *
   * @command compute:vllm-pool-acquire
   * @option workload
   *   Workload to acquire (qwen-vl | whisper).
   * @option model
   *   Optional model override.
   * @option no-fresh
   *   Refuse to provision a fresh instance when the pool has no usable member.
   */
  public function acquire(array $options = ['workload' => 'qwen-vl', 'model' => NULL, 'no-fresh' => FALSE]): void {
    $record = $this->poolManager->acquire(
      (string) ($options['workload'] ?? 'qwen-vl'),
      isset($options['model']) && (string) $options['model'] !== '' ? (string) $options['model'] : NULL,
      !((bool) ($options['no-fresh'] ?? FALSE)),
    );

    $this->output()->writeln(sprintf(
      'Acquired %s status=%s workload=%s model=%s url=%s',
      (string) ($record['contract_id'] ?? ''),
      (string) ($record['lease_status'] ?? ''),
      (string) ($record['current_workload_mode'] ?? ''),
      (string) ($record['current_model'] ?? ''),
      (string) ($record['url'] ?? '')
    ));
  }

  /**
   * Releases a pooled instance back to the available pool.
   *
   * @param string $instanceId
   *   Contract ID to release.
   *
   * @command compute:vllm-pool-release
   */
  public function release(string $instanceId): void {
    $record = $this->poolManager->release($instanceId);
    $this->output()->writeln('Released pooled instance ' . (string) $record['contract_id'] . '.');
  }

  /**
   * Stops available pooled instances after the post-lease grace period.
   *
   * @param array<string,mixed> $options
   *   Command options keyed by idle-seconds and dry-run.
   *
   * @command compute:vllm-pool-reap-idle
   * @option idle-seconds
   *   Override the configured post-lease grace period. Defaults to state
   *   compute_orchestrator.vllm_pool.idle_shutdown_seconds, or 600 seconds.
   * @option dry-run
   *   Report matching instances without stopping them.
   */
  public function reapIdle(array $options = ['idle-seconds' => NULL, 'dry-run' => FALSE]): void {
    $idleSeconds = isset($options['idle-seconds']) && (string) $options['idle-seconds'] !== ''
      ? (int) $options['idle-seconds']
      : $this->poolManager->getIdleShutdownSeconds();
    $results = $this->poolManager->reapIdleAvailableInstances($idleSeconds, (bool) ($options['dry-run'] ?? FALSE));
    if ($results === []) {
      $this->output()->writeln(sprintf('No available pooled instances exceeded the %d second post-lease grace period.', $idleSeconds));
      return;
    }

    foreach ($results as $result) {
      $this->output()->writeln(sprintf(
        '%s action=%s message=%s',
        $result['contract_id'] ?? '',
        $result['action'] ?? '',
        $result['message'] ?? '',
      ));
    }
  }

  /**
   * Removes one instance from the pool inventory.
   *
   * @param string $instanceId
   *   Contract ID to remove.
   *
   * @command compute:vllm-pool-remove
   */
  public function remove(string $instanceId): void {
    $this->poolManager->remove($instanceId);
    $this->output()->writeln('Removed pooled instance ' . $instanceId . '.');
  }

  /**
   * Clears the entire state-backed pool inventory.
   *
   * @command compute:vllm-pool-clear
   */
  public function clear(): void {
    $this->poolManager->clear();
    $this->output()->writeln('Cleared pooled instance inventory.');
  }

}
