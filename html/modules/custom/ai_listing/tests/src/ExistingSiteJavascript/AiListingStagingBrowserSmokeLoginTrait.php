<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

/**
 * Shared staging login helpers for AI listing browser smoke tests.
 */
trait AiListingStagingBrowserSmokeLoginTrait {

  /**
   * Logs into staging with a one-time URL.
   */
  protected function loginToStaging(string $baseUrl, string $loginUrl): void {
    $this->assertNotSame('', trim($baseUrl), 'Expected non-empty staging base URL.');
    $this->assertNotSame('', trim($loginUrl), 'Expected non-empty staging login URL.');

    $this->getSession()->visit(trim($loginUrl));
    $this->assertSession()->waitForText('Log out', 30000);
  }

  /**
   * Returns a required environment variable or skips the smoke.
   */
  protected function requiredEnv(string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
      $this->markTestSkipped("Set {$name} to run this staging smoke test.");
    }

    return trim($value);
  }

  /**
   * Visits an absolute staging URL.
   */
  protected function visitStagingPath(string $baseUrl, string $path): void {
    $this->getSession()->visit(rtrim($baseUrl, '/') . '/' . ltrim($path, '/'));
  }

}
