<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Symfony\Component\Process\Process;

final class SshProbeExecutor {

  public function run(string $sshHost, int $sshPort, string $sshUser, string $keyPath, string $command, int $timeoutSeconds = 10): array {
    $process = new Process([
      'ssh',
      '-o', 'BatchMode=yes',
      '-o', 'StrictHostKeyChecking=no',
      '-o', 'UserKnownHostsFile=/dev/null',
      '-o', 'LogLevel=ERROR',
      '-o', 'ConnectTimeout=5',
      '-i', $keyPath,
      '-p', (string) $sshPort,
      $sshUser . '@' . $sshHost,
      'bash', '-lc', $command,
    ]);

    $process->setTimeout($timeoutSeconds);

    try {
      $process->run();
    } catch (\Throwable $e) {
      return [
        'ok' => false,
        'exit_code' => null,
        'stdout' => '',
        'stderr' => '',
        'exception' => $e->getMessage(),
      ];
    }

    return [
      'ok' => $process->isSuccessful(),
      'exit_code' => $process->getExitCode(),
      'stdout' => trim($process->getOutput()),
      'stderr' => trim($process->getErrorOutput()),
      'exception' => null,
    ];
  }

}
