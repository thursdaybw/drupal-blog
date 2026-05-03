<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Orchestrates the generic vLLM runtime on a Vast instance.
 */
interface GenericVllmRuntimeManagerInterface {

  /**
   * Provisions a fresh generic image instance and waits for SSH bootstrap.
   *
   * @param array<string, mixed> $workloadDefinition
   *   Normalized workload definition.
   * @param string $image
   *   Generic image tag to provision.
   *
   * @return array{contract_id:string,instance_info:array<string,mixed>}
   *   Fresh contract and bootstrap instance metadata.
   */
  public function provisionFresh(array $workloadDefinition, string $image): array;

  /**
   * Waits for a generic image instance to become SSH-ready.
   *
   * @return array<string,mixed>
   *   Latest Vast instance information.
   */
  public function waitForSshBootstrap(string $contractId, int $timeoutSeconds = 600): array;

  /**
   * Starts a workload on a bootstrapped instance.
   *
   * @param array<string,mixed> $instanceInfo
   *   Vast instance metadata including SSH connection fields.
   * @param array<string, mixed> $workloadDefinition
   *   Normalized workload definition.
   */
  public function startWorkload(array $instanceInfo, array $workloadDefinition): void;

  /**
   * Stops the currently active model server on an instance.
   *
   * @param array<string,mixed> $instanceInfo
   *   Vast instance metadata including SSH connection fields.
   */
  public function stopWorkload(array $instanceInfo): void;

  /**
   * Waits for the workload endpoint to become ready.
   *
   * @return array<string,mixed>
   *   Ready instance metadata.
   */
  public function waitForWorkloadReady(string $contractId, int $timeoutSeconds = 900): array;

}
