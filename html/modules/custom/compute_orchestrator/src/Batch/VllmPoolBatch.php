<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Batch;

/**
 * Batch callbacks for vLLM pool actions.
 *
 * Drupal batches must reference callables that survive multiple HTTP requests.
 * That constraint generally pushes us to static callbacks. We intentionally
 * keep framework lookups confined to this glue layer.
 *
 * The core pool logic remains in services with dependency injection.
 */
final class VllmPoolBatch {

  /**
   * Batch operation: run the acquire call.
   *
   * @param string $workload
   *   Workload to acquire.
   * @param array<string,mixed> $context
   *   Batch context.
   */
  public static function batchAcquireOperation(string $workload, array &$context): void {
    $context['results']['workload'] = $workload;
    $context['sandbox']['attempts'] = (int) ($context['sandbox']['attempts'] ?? 0);
    $context['message'] = t('Checking pooled instances for @workload...', ['@workload' => $workload]);

    try {
      /** @var \Drupal\compute_orchestrator\Service\VllmPoolManager $pool */
      $pool = \Drupal::service('compute_orchestrator.vllm_pool_manager');
      $record = $pool->acquire($workload, NULL, TRUE, 25, 25);
      $context['results']['record'] = $record;
      $context['message'] = t('Workload ready on contract @id.', [
        '@id' => (string) ($record['contract_id'] ?? ''),
      ]);
      $context['finished'] = 1;
      return;
    }
    catch (\Drupal\compute_orchestrator\Exception\AcquirePendingException $exception) {
      $context['sandbox']['attempts']++;
      $context['message'] = t('Still warming runtime (attempt @attempt): @message', [
        '@attempt' => (string) $context['sandbox']['attempts'],
        '@message' => $exception->getMessage(),
      ]);
      $context['finished'] = min(0.95, 0.02 * $context['sandbox']['attempts']);
      return;
    }
    catch (\Throwable $exception) {
      $context['results']['error'] = $exception->getMessage();
      $context['message'] = t('Acquire failed while starting @workload runtime.', ['@workload' => $workload]);
      $context['finished'] = 1;
      return;
    }
  }

  /**
   * Batch finished callback for acquire.
   *
   * @param bool $success
   *   Success status.
   * @param array<string,mixed> $results
   *   Batch results.
   */
  public static function batchFinishedAcquire(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    $workload = (string) ($results['workload'] ?? '');

    if (!$success) {
      $messenger->addError(t('Acquire failed for @workload due to a batch processing error.', [
        '@workload' => $workload !== '' ? $workload : 'requested workload',
      ]));
      return;
    }

    if (!empty($results['error'])) {
      $messenger->addError(t('Acquire failed for @workload: @error', [
        '@workload' => $workload !== '' ? $workload : 'requested workload',
        '@error' => (string) $results['error'],
      ]));
      return;
    }

    $record = isset($results['record']) && is_array($results['record']) ? $results['record'] : [];
    $messenger->addStatus(t('Acquired @id workload=@workload url=@url', [
      '@id' => (string) ($record['contract_id'] ?? ''),
      '@workload' => $workload,
      '@url' => (string) ($record['url'] ?? ''),
    ]));
    if (!empty($record['lease_expires_at'])) {
      $messenger->addStatus(t('Lease expires at unix timestamp @ts (renew before expiry).', [
        '@ts' => (string) $record['lease_expires_at'],
      ]));
    }
  }

}
