<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Provides access to Vast credentials.
 */
interface VastCredentialProviderInterface {

  /**
   * Returns the Vast API key, or NULL if not configured.
   */
  public function getApiKey(): ?string;

}
