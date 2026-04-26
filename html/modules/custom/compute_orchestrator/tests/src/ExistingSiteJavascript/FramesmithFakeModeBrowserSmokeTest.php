<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

use Drupal\Core\State\StateInterface;
use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

require_once __DIR__ . '/FramesmithBrowserSmokeFlowTrait.php';

/**
 * Browser smoke test for the fake-mode Framesmith transcription flow.
 *
 * @group compute_orchestrator
 */
final class FramesmithFakeModeBrowserSmokeTest extends DesktopTestBase {

  use FramesmithBrowserSmokeFlowTrait;

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

    $this->runFramesmithTranscriptionSmokeFlow(
      '/framesmith/',
      '/framesmith-browser-smoke.mp4',
      $this->framesmithTranscriptionExecutorMode === 'fake',
      $this->transcriptionUiTimeoutSeconds(),
    );
  }

  /**
   * Returns the UI wait budget for the selected backend mode.
   */
  private function transcriptionUiTimeoutSeconds(): int {
    return $this->framesmithTranscriptionExecutorMode === 'fake' ? 30 : 900;
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
