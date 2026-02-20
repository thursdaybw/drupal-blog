<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

final class SshConnectionContext {

  public function __construct(
    public readonly string $host,
    public readonly int $port,
    public readonly string $user,
    public readonly string $keyPath,
  ) {}

}

