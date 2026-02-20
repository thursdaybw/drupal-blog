<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

final class SshProbeRequest {

  public function __construct(
    public readonly string $name,
    public readonly string $command,
    public readonly int $timeoutSeconds = 10,
  ) {}

}

