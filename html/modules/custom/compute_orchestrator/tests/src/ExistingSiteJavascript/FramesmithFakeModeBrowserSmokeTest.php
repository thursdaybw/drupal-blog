<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

use Drupal\Core\State\StateInterface;
use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

/**
 * Browser smoke test for the fake-mode Framesmith transcription flow.
 *
 * @group compute_orchestrator
 */
final class FramesmithFakeModeBrowserSmokeTest extends DesktopTestBase {

  /**
   * State key that selects fake or real Framesmith transcription execution.
   */
  private const EXECUTOR_MODE_STATE_KEY = 'compute_orchestrator.framesmith_transcription_executor_mode';

  /**
   * Executor mode used by this smoke test.
   *
   * Set this to 'real' for an explicit opt-in real Vast-backed stress run.
   * Keep the default as 'fake' so normal DTT runs never spend real compute.
   */
  protected string $framesmithTranscriptionExecutorMode = 'fake';

  /**
   * Previous executor mode so tearDown can restore operator state.
   */
  private mixed $previousFramesmithTranscriptionExecutorMode = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $user = User::load(1);
    $this->assertNotNull($user, 'User 1 must exist for existing-site Framesmith smoke tests.');
    $this->drupalLogin($user);

    $state = $this->state();
    $this->previousFramesmithTranscriptionExecutorMode = $state->get(self::EXECUTOR_MODE_STATE_KEY, NULL);
    $state->set(self::EXECUTOR_MODE_STATE_KEY, $this->framesmithTranscriptionExecutorMode);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $state = $this->state();
    if ($this->previousFramesmithTranscriptionExecutorMode === NULL) {
      $state->delete(self::EXECUTOR_MODE_STATE_KEY);
    }
    else {
      $state->set(self::EXECUTOR_MODE_STATE_KEY, $this->previousFramesmithTranscriptionExecutorMode);
    }

    parent::tearDown();
  }

  /**
   * Exercises the real served Framesmith UI against the selected backend mode.
   */
  public function testFramesmithTranscribesFixtureInSelectedMode(): void {
    $this->ensureSilentFixtureVideo();

    $fixtureUrl = '/framesmith-browser-smoke.mp4';
    $this->visit('/framesmith/?fixture=' . rawurlencode($fixtureUrl));

    $this->assertSession()->waitForText('Video ready', 30000);
    $this->assertSession()->buttonExists('Transcribe');

    $this->getSession()->getPage()->pressButton('Transcribe');

    $this->assertSession()->waitForText('Captions ready', 30000);

    $deadline = time() + $this->transcriptionUiTimeoutSeconds();
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

    $deadline = time() + $this->transcriptionUiTimeoutSeconds();
    $panelText = '';
    while (time() < $deadline) {
      $panelText = $this->getSession()->getPage()->find('css', '#transcriptPanelText')?->getText() ?? '';
      if ($this->transcriptPanelIsReady($panelText)) {
        break;
      }
      usleep(250000);
    }

    if ($this->framesmithTranscriptionExecutorMode === 'fake') {
      $this->assertStringContainsString(
        'Fake Framesmith transcript for audio.wav.',
        $panelText,
        "Transcript panel text: {$panelText}
Status text: {$statusText}",
      );
      return;
    }

    $this->assertNotSame(
      '',
      trim($panelText),
      "Transcript panel text should not be empty. Status text: {$statusText}",
    );
  }

  /**
   * Returns the UI wait budget for the selected backend mode.
   */
  private function transcriptionUiTimeoutSeconds(): int {
    return $this->framesmithTranscriptionExecutorMode === 'fake' ? 30 : 900;
  }

  /**
   * Returns TRUE when the transcript panel has reached expected mode output.
   */
  private function transcriptPanelIsReady(string $panelText): bool {
    if ($this->framesmithTranscriptionExecutorMode === 'fake') {
      return str_contains($panelText, 'Fake Framesmith transcript for audio.wav.');
    }

    return trim($panelText) !== '';
  }

  /**
   * Returns Drupal state storage.
   */
  private function state(): StateInterface {
    return \Drupal::state();
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

}
