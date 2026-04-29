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
    $this->assertSession()->waitForText('Video ready', $this->videoReadyTimeoutMilliseconds());
    $this->assertSession()->buttonExists('Transcribe');
    $this->installFramesmithUploadStressHarness();

    $this->getSession()->getPage()->pressButton('Transcribe');

    $this->assertSession()->waitForText('Captions ready', $timeoutSeconds * 1000);

    $transcriptButton = $this->waitForTranscriptButton($timeoutSeconds);
    $statusText = $this->getFramesmithStatusText();

    $this->assertNotNull($transcriptButton, 'Transcript button should exist.');
    $this->assertFalse(
      $transcriptButton->hasAttribute('disabled'),
      "Transcript button never enabled. Status text: {$statusText}",
    );

    $transcriptButton->click();
    $panelText = $this->waitForTranscriptPanelText($expectFakeTranscript, $timeoutSeconds);

    $this->assertFramesmithUploadStressHarness();

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
   * Installs a browser-side upload observer and stress harness.
   */
  private function installFramesmithUploadStressHarness(): void {
    $delayMs = $this->envInt('FRAMESMITH_SMOKE_UPLOAD_DELAY_MS', 0);
    $dropFirstUploadChunk = $this->envFlag('FRAMESMITH_SMOKE_DROP_FIRST_UPLOAD_CHUNK');

    $script = sprintf(
      <<<'JS'
(function () {
  if (window.__framesmithSmokeUpload && window.__framesmithSmokeUpload.installed) {
    return;
  }
  const originalFetch = window.fetch.bind(window);
  const state = {
    installed: true,
    calls: [],
    failedOnce: false,
    delayMs: %d,
    dropFirstUploadChunk: %s
  };
  window.__framesmithSmokeUpload = state;
  window.fetch = async function framesmithSmokeFetch(resource, init) {
    const url = typeof resource === 'string'
      ? resource
      : (resource && typeof resource.url === 'string' ? resource.url : '');
    if (url.includes('/api/framesmith/transcription/upload')) {
      const parsed = new URL(url, window.location.href);
      const index = Number(parsed.searchParams.get('index'));
      const total = Number(parsed.searchParams.get('total'));
      state.calls.push({
        index,
        total,
        uploadId: parsed.searchParams.get('upload_id') || '',
        at: Date.now()
      });
      if (state.delayMs > 0) {
        await new Promise((resolve) => setTimeout(resolve, state.delayMs));
      }
      if (state.dropFirstUploadChunk && !state.failedOnce) {
        state.failedOnce = true;
        throw new TypeError('Simulated mobile network drop during Framesmith upload chunk');
      }
    }
    return originalFetch(resource, init);
  };
}());
JS,
      $delayMs,
      $dropFirstUploadChunk ? 'true' : 'false',
    );

    $this->getSession()->executeScript($script);
  }

  /**
   * Asserts the browser-side upload stress harness saw chunked upload behavior.
   */
  private function assertFramesmithUploadStressHarness(): void {
    if (!$this->envFlag('FRAMESMITH_SMOKE_REQUIRE_CHUNKED_UPLOAD')) {
      return;
    }

    $state = $this->getSession()->evaluateScript('window.__framesmithSmokeUpload || null');
    $this->assertIsArray($state, 'Framesmith upload smoke harness was not installed.');

    $calls = $state['calls'] ?? [];
    $this->assertIsArray($calls, 'Framesmith upload smoke harness did not record calls.');
    $this->assertNotEmpty($calls, 'Framesmith upload smoke harness saw no upload calls.');

    $maxTotal = 0;
    $indices = [];
    foreach ($calls as $call) {
      if (!is_array($call)) {
        continue;
      }
      $total = (int) ($call['total'] ?? 0);
      $index = (int) ($call['index'] ?? -1);
      $maxTotal = max($maxTotal, $total);
      if ($index >= 0) {
        $indices[$index] = TRUE;
      }
    }

    $this->assertGreaterThan(
      1,
      $maxTotal,
      'Framesmith uploaded the transcription audio as a single request, not chunks.',
    );
    $this->assertGreaterThan(
      1,
      count($calls),
      'Framesmith upload smoke expected multiple upload requests.',
    );
    $this->assertGreaterThan(
      1,
      count($indices),
      'Framesmith upload smoke expected multiple chunk indexes.',
    );

    if ($this->envFlag('FRAMESMITH_SMOKE_DROP_FIRST_UPLOAD_CHUNK')) {
      $this->assertTrue(
        (bool) ($state['failedOnce'] ?? FALSE),
        'Framesmith upload smoke did not simulate the requested network drop.',
      );
      $this->assertGreaterThan(
        $maxTotal,
        count($calls),
        'Framesmith upload did not retry after the simulated network drop.',
      );
    }
  }

  /**
   * Returns the video-ready wait budget in milliseconds.
   */
  private function videoReadyTimeoutMilliseconds(): int {
    return $this->envInt('FRAMESMITH_SMOKE_VIDEO_READY_TIMEOUT_MS', 120000);
  }

  /**
   * Returns an integer environment setting.
   */
  private function envInt(string $name, int $default): int {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
      return $default;
    }
    if (!preg_match('/^\d+$/', trim($value))) {
      return $default;
    }

    return (int) trim($value);
  }

  /**
   * Returns TRUE when an environment flag is enabled.
   */
  private function envFlag(string $name): bool {
    $value = getenv($name);
    if (!is_string($value)) {
      return FALSE;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
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
