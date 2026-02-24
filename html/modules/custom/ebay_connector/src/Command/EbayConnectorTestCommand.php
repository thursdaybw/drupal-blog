<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Command;

use Drupal\ebay_connector\Service\OAuthTokenService;
use Drush\Commands\DrushCommands;

final class EbayConnectorTestCommand extends DrushCommands {

  public function __construct(
    private readonly OAuthTokenService $oauthTokenService,
  ) {
    parent::__construct();
  }

  /**
   * Test client credentials OAuth token request.
   *
   * @command ebay-connector:test-client
   * @aliases ec-test
   */
  public function testClient(): void {

    $scopes = [
      'https://api.ebay.com/oauth/api_scope',
    ];

    try {
      $tokenData = $this->oauthTokenService
        ->requestClientCredentialsToken($scopes);

      $this->output()->writeln('Access token received.');
      $this->output()->writeln('Expires in: ' . ($tokenData['expires_in'] ?? 'unknown'));

    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }

}
