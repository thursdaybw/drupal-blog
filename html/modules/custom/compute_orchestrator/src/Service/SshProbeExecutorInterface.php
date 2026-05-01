<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Runs SSH probes and returns normalized diagnostics.
 */
interface SshProbeExecutorInterface {

  /**
   * Runs a probe request over SSH.
   *
   * @return array<string,mixed>
   *   Normalized probe result.
   */
  public function run(SshConnectionContext $context, SshProbeRequest $request): array;

}
