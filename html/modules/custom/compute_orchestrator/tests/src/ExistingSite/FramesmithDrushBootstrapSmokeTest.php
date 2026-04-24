<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Verifies Drush can bootstrap with Framesmith command wiring enabled.
 *
 * This guards against service-definition regressions that may not appear in
 * unit or kernel tests but can still break Drush bootstrap for the live site.
 *
 * @group compute_orchestrator
 */
final class FramesmithDrushBootstrapSmokeTest extends ExistingSiteBase {

  /**
   * Verifies Drush bootstraps and registers the Framesmith command.
   */
  public function testDrushBootstrapsAndRegistersFramesmithCommand(): void {
    $repoRoot = dirname((string) \Drupal::root());
    $drushBinary = $repoRoot . '/vendor/bin/drush';

    $this->assertFileExists($drushBinary, 'Expected vendor/bin/drush to exist.');

    [$statusExitCode, $statusOutput] = $this->runDrushCommand(
      sprintf('%s status --fields=bootstrap --format=json', escapeshellarg($drushBinary)),
      $repoRoot,
    );

    $this->assertSame(
      0,
      $statusExitCode,
      "Drush bootstrap failed. Output:\n" . $statusOutput,
    );

    $statusPayload = json_decode($statusOutput, TRUE);
    $this->assertIsArray(
      $statusPayload,
      'Expected JSON output from `drush status --format=json`. Raw output: ' . $statusOutput,
    );
    $this->assertSame(
      'Successful',
      $statusPayload['bootstrap'] ?? NULL,
      'Drush did not report a successful bootstrap. Raw output: ' . $statusOutput,
    );

    [$listExitCode, $listOutput] = $this->runDrushCommand(
      sprintf('%s list --raw', escapeshellarg($drushBinary)),
      $repoRoot,
    );

    $this->assertSame(
      0,
      $listExitCode,
      "Drush list failed. Output:\n" . $listOutput,
    );
    $this->assertStringContainsString(
      'compute:framesmith-run-transcription',
      $listOutput,
      "Framesmith Drush command was not registered. Output:\n" . $listOutput,
    );
  }

  /**
   * Runs one Drush command inside the repository root.
   *
   * @return array{0:int,1:string}
   *   Exit code and combined output.
   */
  private function runDrushCommand(string $command, string $repoRoot): array {
    $fullCommand = sprintf(
      'cd %s && %s 2>&1',
      escapeshellarg($repoRoot),
      $command,
    );

    $output = [];
    $exitCode = 0;
    exec($fullCommand, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
  }

}
