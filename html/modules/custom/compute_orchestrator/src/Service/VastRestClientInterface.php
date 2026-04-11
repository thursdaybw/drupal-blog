<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Defines the Vast REST client contract used by compute orchestration.
 */
interface VastRestClientInterface {

  /**
   * Search for available offers.
   *
   * @param string $query
   *   Vast search query string.
   * @param int $limit
   *   Maximum number of offers to return.
   *
   * @return array
   *   Decoded JSON response.
   */
  public function searchOffers(string $query, int $limit = 20): array;

  /**
   * Create a new instance from an offer.
   *
   * @param string $offerId
   *   The offer ID.
   * @param string $image
   *   Docker image to run.
   * @param array $options
   *   Optional Vast create options payload.
   *
   * @return array
   *   Decoded JSON response.
   */
  public function createInstance(string $offerId, string $image, array $options = []): array;

  /**
   * Start an existing instance.
   */
  public function startInstance(string $instanceId): array;

  /**
   * Fetch instance information.
   */
  public function showInstance(string $instanceId): array;

  /**
   * Destroy an instance.
   */
  public function destroyInstance(string $instanceId): array;

  /**
   * Fetch instance logs (regular or debug) for diagnostics.
   *
   * @param string $instanceId
   *   Instance ID to inspect.
   * @param bool $extra
   *   Whether to include the "extra debug logs" stream.
   *
   * @return array
   *   Decoded JSON response from Vast.
   */
  public function getInstanceLogs(string $instanceId, bool $extra = FALSE): array;

  /**
   * Search offers using structured filters.
   *
   * @param array $filters
   *   Structured filter array matching Vast REST format.
   * @param int $limit
   *   Maximum number of results.
   *
   * @return array
   *   Decoded JSON response.
   */
  public function searchOffersStructured(array $filters, int $limit = 20): array;

  /**
   * Selects the best offer from the current candidate pool.
   */
  public function selectBestOffer(
    array $filters,
    array $excludeHostIds = [],
    array $excludeRegions = [],
    int $limit = 20,
  ): ?array;

  /**
   * Provisions and boots a workload-specific instance from the offers API.
   */
  public function provisionInstanceFromOffers(
    array $filters,
    array $excludeRegions = [],
    int $limit = 5,
    ?float $maxPrice = NULL,
    ?float $minPrice = NULL,
    array $createOptions = [],
    int $maxAttempts = 5,
    int $bootTimeoutSeconds = 600,
  ): array;

  /**
   * Waits for a workload-aware instance to reach SSH and service readiness.
   */
  public function waitForRunningAndSsh(string $instanceId, string $workload = 'vllm', int $timeoutSeconds = 180): array;

}
