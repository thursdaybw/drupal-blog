<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Executes probe commands over SSH.
 */
final class SshProbeExecutor {

  private const JSONL_LOG_PATH = '/tmp/compute-orchestrator-ssh-probes.log';

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get('compute_orchestrator');
  }

  /**
   * Runs a probe request over SSH and returns a normalized result structure.
   */
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
    $this->appendJsonlLog([
      'event' => 'invoke',
      'timestamp' => date(DATE_ATOM),
      'probe' => $request->name,
      'host' => $context->host,
      'port' => $context->port,
      'user' => $context->user,
      'remote_command' => $remoteCommand,
      'timeout_seconds' => $request->timeoutSeconds,
    ]);

    $process = new Process([
      'ssh',
      '-F', '/dev/null',
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
    }
    catch (\Throwable $e) {
      $result = [
        'ok' => FALSE,
        'transport_ok' => FALSE,
        'failure_kind' => 'transport',
        'exit_code' => NULL,
        'stdout' => '',
        'stderr' => '',
        'exception' => $e->getMessage(),
      ];
      $this->logProbeResult($request->name, $result);
      $this->appendProbeResultLog($request->name, $context, $remoteCommand, $result);
      return $result;
    }

    $result = [
      'ok' => $process->isSuccessful(),
      'transport_ok' => TRUE,
      'failure_kind' => $process->isSuccessful() ? 'none' : 'command',
      'exit_code' => $process->getExitCode(),
      'stdout' => trim($process->getOutput()),
      'stderr' => trim($process->getErrorOutput()),
      'exception' => NULL,
    ];
    $this->logProbeResult($request->name, $result);
    $this->appendProbeResultLog($request->name, $context, $remoteCommand, $result);
    return $result;
  }

  /**
   * Appends a normalized probe result row to the JSONL diagnostics log.
   *
   * @param string $probe
   *   The probe name.
   * @param \Drupal\compute_orchestrator\Service\SshConnectionContext $context
   *   SSH connection details used for the probe.
   * @param string $remoteCommand
   *   The remote command that was executed.
   * @param array<string, mixed> $result
   *   The normalized probe result payload.
   */
  private function appendProbeResultLog(string $probe, SshConnectionContext $context, string $remoteCommand, array $result): void {
    $this->appendJsonlLog([
      'event' => 'result',
      'timestamp' => date(DATE_ATOM),
      'probe' => $probe,
      'host' => $context->host,
      'port' => $context->port,
      'user' => $context->user,
      'remote_command' => $remoteCommand,
      'ok' => ($result['ok'] ?? FALSE) === TRUE,
      'transport_ok' => ($result['transport_ok'] ?? FALSE) === TRUE,
      'failure_kind' => (string) ($result['failure_kind'] ?? 'unknown'),
      'exit_code' => $result['exit_code'] ?? NULL,
      'stdout' => (string) ($result['stdout'] ?? ''),
      'stderr' => (string) ($result['stderr'] ?? ''),
      'exception' => (string) ($result['exception'] ?? ''),
    ]);
  }

  /**
   * Appends a single JSONL diagnostics row to disk.
   *
   * @param array<string, mixed> $row
   *   The row to append.
   */
  private function appendJsonlLog(array $row): void {
    try {
      @file_put_contents(self::JSONL_LOG_PATH, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to append SSH probe JSONL log: {message}', ['message' => $e->getMessage()]);
    }
  }

  /**
   * Logs a normalized SSH probe outcome.
   *
   * @param string $probe
   *   Probe name.
   * @param array<string,mixed> $result
   *   Probe result array.
   */
  private function logProbeResult(string $probe, array $result): void {
    $context = [
      'probe' => $probe,
      'ok' => (($result['ok'] ?? FALSE) === TRUE) ? '1' : '0',
      'transport_ok' => (($result['transport_ok'] ?? FALSE) === TRUE) ? '1' : '0',
      'failure_kind' => (string) ($result['failure_kind'] ?? 'unknown'),
      'exit_code' => isset($result['exit_code']) ? (string) $result['exit_code'] : '(null)',
      'stdout' => $this->summarizeLogField((string) ($result['stdout'] ?? '')),
      'stderr' => $this->summarizeLogField((string) ($result['stderr'] ?? '')),
      'exception' => $this->summarizeLogField((string) ($result['exception'] ?? '')),
    ];

    if (($result['ok'] ?? FALSE) === TRUE) {
      $this->logger->debug(
        'SSH probe result ({probe}) ok={ok} transport_ok={transport_ok} failure_kind={failure_kind} exit={exit_code} stdout={stdout} stderr={stderr}',
        $context,
      );
      return;
    }

    $this->logger->warning(
      'SSH probe result ({probe}) ok={ok} transport_ok={transport_ok} failure_kind={failure_kind} exit={exit_code} stdout={stdout} stderr={stderr} exception={exception}',
      $context,
    );
  }

  /**
   * Truncates log field content to keep watchdog readable.
   */
  private function summarizeLogField(string $value): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
      return '(empty)';
    }
    if (strlen($value) > 500) {
      return substr($value, 0, 500) . '…';
    }
    return $value;
  }

}
