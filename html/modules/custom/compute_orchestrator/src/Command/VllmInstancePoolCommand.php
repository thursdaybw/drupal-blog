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
    $this->output()->writeln(
      'Registered pooled instance ' . (string) $record['contract_id'] . '.',
    );
  }

  /**
   * Lists the current state-backed pool inventory.
   *
   * Output uses explicit lease/runtime wording.
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
        '%s lease_status=%s runtime_state=%s workload=%s model=%s url=%s source=%s last_phase=%s last_action=%s',
        $contractId,
        (string) ($record['lease_status'] ?? ''),
        (string) ($record['runtime_state'] ?? ''),
        (string) ($record['current_workload_mode'] ?? ''),
        (string) ($record['current_model'] ?? ''),
        (string) ($record['url'] ?? ''),
        (string) ($record['source'] ?? ''),
        (string) ($record['last_phase'] ?? ''),
        (string) ($record['last_action'] ?? '')
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
      'Acquired runtime lease contract=%s lease_status=%s runtime_state=%s workload=%s model=%s url=%s',
      (string) ($record['contract_id'] ?? ''),
      (string) ($record['lease_status'] ?? ''),
      (string) ($record['runtime_state'] ?? ''),
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
    $this->output()->writeln(sprintf(
      'Released runtime lease contract=%s lease_status=%s runtime_state=%s message=%s',
      (string) ($record['contract_id'] ?? ''),
      (string) ($record['lease_status'] ?? ''),
      (string) ($record['runtime_state'] ?? ''),
      'instance remains reusable; release does not stop or destroy it',
    ));
  }

  /**
   * Stops reusable available pooled instances.
   *
   * The post-lease grace period decides whether an available runtime is old
   * enough to stop.
   *
   * @param array<string,mixed> $options
   *   Command options keyed by idle-seconds and dry-run.
   *
   * @command compute:vllm-pool-reap-idle
   * @option idle-seconds
   *   Override the configured grace period. Use 0 to reap immediately.
   * @option no-reap
   *   Skip reaping entirely for this invocation.
   * @option dry-run
   *   Report matching instances without stopping them.
   */
  public function reapIdle(array $options = ['idle-seconds' => NULL, 'no-reap' => FALSE, 'dry-run' => FALSE]): void {
    if ((bool) ($options['no-reap'] ?? FALSE)) {
      $this->output()->writeln('Reaping skipped because --no-reap was provided.');
      return;
    }

    $idleSecondsOption = $options['idle-seconds'] ?? NULL;
    if ($idleSecondsOption === FALSE) {
      $idleSecondsOption = $this->extractIdleSecondsFromArgv();
    }
    $idleSeconds = $idleSecondsOption !== NULL && (string) $idleSecondsOption !== ''
      ? (int) $idleSecondsOption
      : $this->poolManager->getIdleShutdownSeconds();
    $results = $this->poolManager->reapIdleAvailableInstances($idleSeconds, (bool) ($options['dry-run'] ?? FALSE));
    if ($results === []) {
      $this->output()->writeln(sprintf(
        'No reusable available pooled instances exceeded the %d second post-lease grace period.',
        $idleSeconds,
      ));
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
   * Reconciles tracked pool records against real Vast state.
   *
   * @param array<string,mixed> $options
   *   Command options keyed by dry-run.
   *
   * @command compute:vllm-pool-reconcile
   * @option dry-run
   *   Report proposed fixes without saving them.
   */
  public function reconcile(array $options = ['dry-run' => FALSE]): void {
    $results = $this->poolManager->reconcile((bool) ($options['dry-run'] ?? FALSE));
    if ($results === []) {
      $this->output()->writeln('No pooled instances are registered.');
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
   * Removes one tracked record from the pool inventory.
   *
   * This does not destroy the Vast instance.
   *
   * @param string $instanceId
   *   Contract ID to remove.
   *
   * @command compute:vllm-pool-remove
   */
  public function remove(string $instanceId): void {
    $this->poolManager->remove($instanceId);
    $this->output()->writeln('Removed pool record ' . $instanceId . ' without destroying the Vast instance.');
  }

  /**
   * Extracts --idle-seconds from raw argv when Drush normalizes zero to FALSE.
   */
  private function extractIdleSecondsFromArgv(): ?int {
    $argv = $_SERVER['argv'] ?? [];
    if (!is_array($argv)) {
      return NULL;
    }

    foreach ($argv as $arg) {
      if (!is_string($arg)) {
        continue;
      }
      if (str_starts_with($arg, '--idle-seconds=')) {
        return (int) substr($arg, strlen('--idle-seconds='));
      }
    }

    return NULL;
  }

  /**
   * Destroys the Vast instance and removes it from the pool inventory.
   *
   * @param string $instanceId
   *   Contract ID to destroy and remove.
   *
   * @command compute:vllm-pool-destroy-remove
   */
  public function destroyRemove(string $instanceId): void {
    $result = $this->poolManager->destroyAndRemove($instanceId);
    $this->output()->writeln(sprintf(
      '%s action=%s message=%s',
      $result['contract_id'] ?? '',
      $result['action'] ?? '',
      $result['message'] ?? '',
    ));
  }

  /**
   * Clears the entire state-backed pool inventory.
   *
   * This does not destroy Vast instances.
   *
   * @command compute:vllm-pool-clear
   */
  public function clear(): void {
    $this->poolManager->clear();
    $this->output()->writeln(
      'Cleared pooled instance inventory. Vast instances were not destroyed.',
    );
  }

}
