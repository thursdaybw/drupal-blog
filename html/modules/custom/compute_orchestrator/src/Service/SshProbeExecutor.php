<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class SshProbeExecutor {

  private LoggerInterface $logger;

  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get('compute_orchestrator');
  }

  public function run(SshConnectionContext $context, SshProbeRequest $request): array {
    $wrappedCommand = 'set -euo pipefail; ' . $request->command;
    $remoteCommand = "bash -lc '" . str_replace("'", "'\"'\"'", $wrappedCommand) . "'";

    $this->logger->debug(
      'SSH probe invoke ({probe}): ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5 -i <key> -p {port} {user}@{host} {remote}',
      [
        'probe' => $request->name,
        'port' => (string) $context->port,
        'user' => $context->user,
        'host' => $context->host,
        'remote' => $remoteCommand,
      ]
    );

    $process = new Process([
      'ssh',
      '-o', 'BatchMode=yes',
      '-o', 'StrictHostKeyChecking=no',
      '-o', 'UserKnownHostsFile=/dev/null',
      '-o', 'LogLevel=ERROR',
      '-o', 'ConnectTimeout=5',
      '-i', $context->keyPath,
      '-p', (string) $context->port,
      $context->user . '@' . $context->host,
      $remoteCommand,
    ]);

    $process->setTimeout($request->timeoutSeconds);

    try {
      $process->run();
    } catch (\Throwable $e) {
      return [
        'ok' => false,
        'transport_ok' => false,
        'failure_kind' => 'transport',
        'exit_code' => null,
        'stdout' => '',
        'stderr' => '',
        'exception' => $e->getMessage(),
      ];
    }

    return [
      'ok' => $process->isSuccessful(),
      'transport_ok' => true,
      'failure_kind' => $process->isSuccessful() ? 'none' : 'command',
      'exit_code' => $process->getExitCode(),
      'stdout' => trim($process->getOutput()),
      'stderr' => trim($process->getErrorOutput()),
      'exception' => null,
    ];
  }

}
