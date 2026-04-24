<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

/**
 * Browser smoke test for the fake-mode Framesmith transcription flow.
 *
 * @group compute_orchestrator
 */
final class FramesmithFakeModeBrowserSmokeTest extends DesktopTestBase {

  /**
   * Exercises the real served Framesmith UI against the fake backend mode.
   */
  public function testFramesmithTranscribesFixtureInFakeMode(): void {
    $this->ensureSilentFixtureVideo();

    $this->runShellCommand(sprintf(
      'cd %s && ./vendor/bin/drush state:set compute_orchestrator.framesmith_transcription_executor_mode fake -y',
      escapeshellarg($this->repoRoot()),
    ));

    $fixtureUrl = '/framesmith-browser-smoke.mp4';
    $this->visit('/framesmith/?fixture=' . rawurlencode($fixtureUrl));

    $this->assertSession()->waitForText('Video ready', 30000);
    $this->assertSession()->buttonExists('Transcribe');

    $this->getSession()->getPage()->pressButton('Transcribe');

    $this->assertSession()->waitForText('Captions ready', 30000);

    $deadline = time() + 30;
    $transcriptButton = NULL;
    while (time() < $deadline) {
      $transcriptButton = $this->getSession()->getPage()->find('css', '#showTranscriptBtn');
      if ($transcriptButton && !$transcriptButton->hasAttribute('disabled')) {
        break;
      }
      usleep(250000);
    }

    $statusText = $this->getSession()->getPage()->find('css', '#videoSourceStatus')?->getText() ?? '';
    $buttonDisabled = $transcriptButton?->hasAttribute('disabled');
    $this->assertNotNull($transcriptButton, 'Transcript button should exist.');
    $this->assertFalse(
      (bool) $buttonDisabled,
      "Transcript button never enabled. Status text: {$statusText}",
    );

    $transcriptButton->click();

    $deadline = time() + 15;
    $panelText = '';
    while (time() < $deadline) {
      $panelText = $this->getSession()->getPage()->find('css', '#transcriptPanelText')?->getText() ?? '';
      if (str_contains($panelText, 'Fake Framesmith transcript for audio.wav.')) {
        break;
      }
      usleep(250000);
    }

    $this->assertStringContainsString(
      'Fake Framesmith transcript for audio.wav.',
      $panelText,
      "Transcript panel text: {$panelText}
Status text: {$statusText}",
    );
  }

  /**
   * Ensures a tiny MP4 fixture with a valid audio track exists.
   */
  private function ensureSilentFixtureVideo(): string {
    $fixturePath = '/var/www/html/html/framesmith-browser-smoke.mp4';
    if (is_file($fixturePath)) {
      return $fixturePath;
    }

    $command = implode(' ', [
      'ffmpeg -y',
      '-f lavfi -i color=c=black:s=320x240:d=2',
      '-f lavfi -i anullsrc=r=16000:cl=mono',
      '-shortest',
      '-c:v libx264',
      '-pix_fmt yuv420p',
      '-c:a aac',
      '-movflags +faststart',
      escapeshellarg($fixturePath),
    ]);

    $this->runShellCommand($command);
    $this->assertFileExists($fixturePath, 'Expected Framesmith browser smoke fixture to exist.');

    return $fixturePath;
  }

  /**
   * Runs a shell command and fails the test on non-zero exit.
   */
  private function runShellCommand(string $command): string {
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $combined = implode("\n", $output);

    $this->assertSame(0, $exitCode, $combined);

    return $combined;
  }

  /**
   * Returns the repository root path.
   */
  private function repoRoot(): string {
    return dirname((string) \Drupal::root());
  }

}
