<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

/**
 * Shared browser flow helpers for Framesmith transcription smoke tests.
 */
trait FramesmithBrowserSmokeFlowTrait {

  /**
   * Exercises the served Framesmith UI with a browser-accessible fixture URL.
   */
  protected function runFramesmithTranscriptionSmokeFlow(
    string $framesmithUrl,
    string $fixtureUrl,
    bool $expectFakeTranscript,
    int $timeoutSeconds,
  ): void {
    $this->visitFramesmithFixture($framesmithUrl, $fixtureUrl);
    $this->runFramesmithTranscriptionFromLoadedSource($expectFakeTranscript, $timeoutSeconds);
  }

  /**
   * Exercises the served Framesmith UI by uploading a local video fixture.
   */
  protected function runFramesmithUploadedFileTranscriptionSmokeFlow(
    string $framesmithUrl,
    string $fixturePath,
    bool $expectFakeTranscript,
    int $timeoutSeconds,
  ): void {
    $this->assertFileExists($fixturePath, 'Expected local Framesmith fixture to exist.');
    $this->visitFramesmith($framesmithUrl);

    $fileInput = $this->assertSession()->waitForElement('css', '#videoFileInput', 30000);
    $this->assertNotNull($fileInput, 'Expected Framesmith file input to exist.');
    $fileInput->attachFile($fixturePath);

    $this->runFramesmithTranscriptionFromLoadedSource($expectFakeTranscript, $timeoutSeconds);
  }

  /**
   * Continues the transcription smoke once a source has been selected/loaded.
   */
  private function runFramesmithTranscriptionFromLoadedSource(bool $expectFakeTranscript, int $timeoutSeconds): void {
    $this->assertSession()->waitForText('Video ready', 30000);
    $this->assertSession()->buttonExists('Transcribe');

    $this->getSession()->getPage()->pressButton('Transcribe');

    $this->assertSession()->waitForText('Captions ready', 30000);

    $transcriptButton = $this->waitForTranscriptButton($timeoutSeconds);
    $statusText = $this->getFramesmithStatusText();

    $this->assertNotNull($transcriptButton, 'Transcript button should exist.');
    $this->assertFalse(
      $transcriptButton->hasAttribute('disabled'),
      "Transcript button never enabled. Status text: {$statusText}",
    );

    $transcriptButton->click();
    $panelText = $this->waitForTranscriptPanelText($expectFakeTranscript, $timeoutSeconds);

    if ($expectFakeTranscript) {
      $this->assertStringContainsString(
        'Fake Framesmith transcript for audio.wav.',
        $panelText,
        "Transcript panel text: {$panelText}\nStatus text: {$statusText}",
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
   * Visits the Framesmith UI with a fixture query parameter.
   */
  protected function visitFramesmithFixture(string $framesmithUrl, string $fixtureUrl): void {
    $separator = str_contains($framesmithUrl, '?') ? '&' : '?';
    $url = $framesmithUrl . $separator . 'fixture=' . rawurlencode($fixtureUrl);
    $this->visitFramesmith($url);
  }

  /**
   * Visits a Framesmith URL.
   */
  protected function visitFramesmith(string $url): void {
    if (preg_match('#^https?://#', $url) === 1) {
      $this->getSession()->visit($url);
      return;
    }

    $this->visit($url);
  }

  /**
   * Logs in through the browser using Drupal's public login form.
   */
  protected function loginThroughBrowser(string $baseUrl, string $username, string $password): void {
    $loginUrl = rtrim($baseUrl, '/') . '/user/login';
    $this->getSession()->visit($loginUrl);

    $page = $this->getSession()->getPage();
    $page->fillField('name', $username);
    $page->fillField('pass', $password);
    $page->pressButton('Log in');

    $this->assertSession()->waitForElementRemoved('css', 'form.user-login-form', 30000);
  }

  /**
   * Waits for the transcript button to exist and become enabled.
   */
  private function waitForTranscriptButton(int $timeoutSeconds): mixed {
    $deadline = time() + $timeoutSeconds;
    $transcriptButton = NULL;
    while (time() < $deadline) {
      $transcriptButton = $this->getSession()->getPage()->find('css', '#showTranscriptBtn');
      if ($transcriptButton && !$transcriptButton->hasAttribute('disabled')) {
        break;
      }
      usleep(250000);
    }

    return $transcriptButton;
  }

  /**
   * Waits for the transcript panel to reach the expected output state.
   */
  private function waitForTranscriptPanelText(bool $expectFakeTranscript, int $timeoutSeconds): string {
    $deadline = time() + $timeoutSeconds;
    $panelText = '';
    while (time() < $deadline) {
      $panelText = $this->getSession()->getPage()->find('css', '#transcriptPanelText')?->getText() ?? '';
      if ($this->transcriptPanelIsReady($panelText, $expectFakeTranscript)) {
        break;
      }
      usleep(250000);
    }

    return $panelText;
  }

  /**
   * Returns TRUE when the transcript panel has reached expected mode output.
   */
  private function transcriptPanelIsReady(string $panelText, bool $expectFakeTranscript): bool {
    if ($expectFakeTranscript) {
      return str_contains($panelText, 'Fake Framesmith transcript for audio.wav.');
    }

    return trim($panelText) !== '';
  }

  /**
   * Returns the current Framesmith status text.
   */
  private function getFramesmithStatusText(): string {
    return $this->getSession()->getPage()->find('css', '#videoSourceStatus')?->getText() ?? '';
  }

}
