<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

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
   *
   * @return array
   *   Decoded JSON response.
   */
  public function createInstance(string $offerId, string $image): array;

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

  public function selectBestOffer(
    array $filters,
    array $excludeHostIds = [],
    array $excludeRegions = [],
    int $limit = 20
  ): ?array;

  public function waitForRunningAndSsh(string $instanceId, int $timeoutSeconds = 180): array;

}

