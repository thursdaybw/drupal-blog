<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves Vast credentials from Drupal configuration.
 *
 * Environment-specific secret sourcing belongs in settings.php through
 * Drupal's config override mechanism.
 */
final class VastCredentialProvider implements VastCredentialProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): ?string {
    $apiKey = trim((string) $this->configFactory
      ->get('compute_orchestrator.settings')
      ->get('vast_api_key'));
    if ($apiKey !== '') {
      return $apiKey;
    }

    return NULL;
  }

}
