<?php

declare(strict_types=1);

namespace Drupal\ai_listing_inference\Service;

use Drupal\compute_orchestrator\Service\VllmPoolManager;

/**
 * Runs ready-for-inference listings through a single pooled inference session.
 */
final class ReadyInferenceRunService {

  public function __construct(
    private readonly AiBookListingBatchDataExtractionProcessor $batchProcessor,
    private readonly VllmPoolManager $vllmPoolManager,
  ) {}

  /**
   * Runs inference for all ready listings under one qwen-vl lease.
   *
   * @param callable|null $onProgress
   *   Optional callback receiving progress messages.
   *
   * @return array<string,mixed>
   *   Structured execution report.
   */
  public function runReadyListings(?callable $onProgress = NULL): array {
    $ids = $this->batchProcessor->getReadyForInferenceListingIds();
    $result = [
      'total' => count($ids),
      'processed' => 0,
      'failed' => 0,
      'errors' => [],
      'items' => [],
      'lease_contract_id' => '',
      'lease_released' => FALSE,
      'aborted_early' => FALSE,
      'acquire_failed' => FALSE,
    ];

    if ($ids === []) {
      $this->emitProgress($onProgress, 'No listings are currently ready for inference.');
      return $result;
    }

    $this->emitProgress($onProgress, sprintf('Acquiring qwen-vl lease for %d listing(s)...', count($ids)));

    try {
      $record = $this->vllmPoolManager->acquire('qwen-vl');
      $contractId = trim((string) ($record['contract_id'] ?? ''));
      if ($contractId === '') {
        throw new \RuntimeException('Pool acquire did not return a contract ID.');
      }
      $result['lease_contract_id'] = $contractId;
      $this->emitProgress($onProgress, sprintf('Lease acquired: %s', $contractId));
    }
    catch (\Throwable $exception) {
      $result['failed'] = count($ids);
      $result['acquire_failed'] = TRUE;
      $result['errors'][] = 'Could not acquire qwen-vl lease from pool: ' . $exception->getMessage();
      $result['errors'][] = 'Check /admin/compute-orchestrator/vllm-pool for last error details.';
      $this->emitProgress($onProgress, 'Inference stopped: lease acquisition failed.');
      return $result;
    }

    $contractId = (string) $result['lease_contract_id'];
    try {
      $total = count($ids);
      foreach (array_values($ids) as $index => $listingId) {
        $itemStart = microtime(TRUE);
        $listing = $this->batchProcessor->loadListing($listingId);
        if ($listing === NULL) {
          $result['failed']++;
          $message = sprintf('Listing %d/%d missing (ID %d), skipping.', $index + 1, $total, $listingId);
          $result['errors'][] = 'Listing ' . $listingId . ' was not found.';
          $result['items'][] = [
            'listing_id' => $listingId,
            'title' => '',
            'edition' => '',
            'duration' => microtime(TRUE) - $itemStart,
            'success' => FALSE,
            'error' => 'Listing was not found.',
          ];
          $this->emitProgress($onProgress, $message);
          continue;
        }

        $this->emitProgress($onProgress, sprintf(
          'Processing %d/%d (ID %d): %s',
          $index + 1,
          $total,
          $listingId,
          $listing->label() ?: 'Untitled'
        ));

        try {
          $this->batchProcessor->processListing($listing);
          $result['processed']++;
          $result['items'][] = [
            'listing_id' => $listingId,
            'title' => $listing->hasField('field_title') ? (string) ($listing->get('field_title')->value ?? '') : '',
            'edition' => $listing->hasField('field_edition') ? (string) ($listing->get('field_edition')->value ?? '') : '',
            'duration' => microtime(TRUE) - $itemStart,
            'success' => TRUE,
            'error' => '',
          ];
        }
        catch (\Throwable $exception) {
          $result['failed']++;
          $result['errors'][] = 'Listing ' . $listingId . ' inference failed: ' . $exception->getMessage();
          $result['items'][] = [
            'listing_id' => $listingId,
            'title' => $listing->hasField('field_title') ? (string) ($listing->get('field_title')->value ?? '') : '',
            'edition' => $listing->hasField('field_edition') ? (string) ($listing->get('field_edition')->value ?? '') : '',
            'duration' => microtime(TRUE) - $itemStart,
            'success' => FALSE,
            'error' => $exception->getMessage(),
          ];

          if ($this->isConnectivityFailure($exception)) {
            $result['aborted_early'] = TRUE;
            $this->emitProgress($onProgress, 'Aborting run: VLM connectivity failure detected.');
            break;
          }
        }
      }
    }
    finally {
      try {
        $this->vllmPoolManager->release($contractId);
        $result['lease_released'] = TRUE;
        $this->emitProgress($onProgress, sprintf('Released lease: %s', $contractId));
      }
      catch (\Throwable $releaseError) {
        $result['errors'][] = 'Failed to release lease ' . $contractId . ': ' . $releaseError->getMessage();
      }
    }

    return $result;
  }

  /**
   * Emits a progress message to optional callback.
   */
  private function emitProgress(?callable $onProgress, string $message): void {
    if ($onProgress !== NULL) {
      $onProgress($message);
    }
  }

  /**
   * Detects transport/connectivity failures that justify early abort.
   */
  private function isConnectivityFailure(\Throwable $error): bool {
    $message = strtolower($error->getMessage());
    $patterns = [
      'curl error 28',
      'failed to connect',
      'connection refused',
      'could not resolve host',
      'operation timed out',
      'vllm not configured',
      '/v1/chat/completions',
    ];

    foreach ($patterns as $pattern) {
      if (str_contains($message, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
