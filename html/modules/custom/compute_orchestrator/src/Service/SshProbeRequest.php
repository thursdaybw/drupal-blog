<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Value object describing a single SSH probe (name, command, timeout).
 */
final class SshProbeRequest {

  public function __construct(
    public readonly string $name,
    public readonly string $command,
    public readonly int $timeoutSeconds = 10,
  ) {}

}
