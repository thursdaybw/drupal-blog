<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Provides authenticated low-level access to the Vast REST API.
 */
interface VastApiClientInterface {

  /**
   * Executes an authenticated Vast REST request.
   *
   * @param string $method
   *   HTTP method.
   * @param string $uri
   *   Vast API path, relative to the v0 base URL.
   * @param array $options
   *   Guzzle request options.
   *
   * @return array
   *   Decoded JSON response.
   */
  public function request(string $method, string $uri, array $options = []): array;

}
