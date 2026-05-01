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
    $this->waitForFramesmithUploadToLeaveAwaitingUpload();

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
    $dropFirstChunkAttempts = $this->envInt(
      'FRAMESMITH_SMOKE_DROP_FIRST_UPLOAD_CHUNK_ATTEMPTS',
      $dropFirstUploadChunk ? 1 : 0,
    );

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
    simulatedDropCount: 0,
    delayMs: %d,
    dropFirstUploadChunkAttempts: %d
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
      const uploadCall = {
        index,
        total,
        uploadId: parsed.searchParams.get('upload_id') || '',
        at: Date.now(),
        completedAt: 0,
        simulatedDrop: false,
        responseOk: null,
        responseStatus: null,
        error: ''
      };
      state.calls.push(uploadCall);
      if (state.delayMs > 0) {
        await new Promise((resolve) => setTimeout(resolve, state.delayMs));
      }
      if (state.simulatedDropCount < state.dropFirstUploadChunkAttempts && index === 0) {
        state.failedOnce = true;
        state.simulatedDropCount += 1;
        uploadCall.simulatedDrop = true;
        uploadCall.completedAt = Date.now();
        uploadCall.error = 'Simulated mobile network drop during Framesmith upload chunk';
        throw new TypeError(uploadCall.error);
      }
      try {
        const response = await originalFetch(resource, init);
        uploadCall.completedAt = Date.now();
        uploadCall.responseOk = response.ok;
        uploadCall.responseStatus = response.status;
        return response;
      }
      catch (error) {
        uploadCall.completedAt = Date.now();
        uploadCall.error = error && error.message ? error.message : String(error);
        throw error;
      }
    }
    return originalFetch(resource, init);
  };
}());
JS,
      $delayMs,
      $dropFirstChunkAttempts,
    );

    $this->getSession()->executeScript($script);
  }

  /**
   * Fails early when the chunked upload phase stops making useful progress.
   *
   * Local/dev smoke runs use the same browser and HTTPS shape as staging, but
   * the upload leg is loopback-fast. Once the frontend has a task ID, Drupal's
   * task status exposes received chunk counts. That lets the test distinguish
   * upload retry bugs from later provisioning/transcription waits instead of
   * burning the full smoke timeout on a task stuck at awaiting_upload.
   */
  private function waitForFramesmithUploadToLeaveAwaitingUpload(): void {
    $timeoutMs = $this->envInt('FRAMESMITH_SMOKE_UPLOAD_PROGRESS_TIMEOUT_MS', 90000);
    $idleMs = $this->envInt('FRAMESMITH_SMOKE_UPLOAD_IDLE_TIMEOUT_MS', 15000);
    $deadline = microtime(TRUE) + ($timeoutMs / 1000);
    $lastProgressSignature = '';
    $lastProgressAt = microtime(TRUE);
    $lastDiagnostic = [];

    while (microtime(TRUE) < $deadline) {
      $diagnostic = $this->getFramesmithUploadDiagnosticState();
      $lastDiagnostic = $diagnostic;
      $statusText = (string) ($diagnostic['statusText'] ?? '');

      if (str_contains($statusText, 'Transcription failed:')) {
        $this->fail('Framesmith upload failed before provisioning: ' . $this->formatFramesmithUploadDiagnostic($diagnostic));
      }

      $taskStatus = strtolower((string) ($diagnostic['taskStatus'] ?? ''));
      $localAudioPath = trim((string) ($diagnostic['localAudioPath'] ?? ''));
      if ($localAudioPath !== '' || !in_array($taskStatus, ['', 'created', 'awaiting_upload'], TRUE)) {
        return;
      }

      $signature = $this->framesmithUploadProgressSignature($diagnostic);
      if ($signature !== $lastProgressSignature) {
        $lastProgressSignature = $signature;
        $lastProgressAt = microtime(TRUE);
      }

      $hasStartedUpload = (int) ($diagnostic['browserUploadCallCount'] ?? 0) > 0
        || (int) ($diagnostic['backendReceivedCount'] ?? 0) > 0;
      if ($hasStartedUpload && ((microtime(TRUE) - $lastProgressAt) * 1000) > $idleMs) {
        $this->fail('Framesmith upload stopped making progress: ' . $this->formatFramesmithUploadDiagnostic($diagnostic));
      }

      usleep(250000);
    }

    $this->fail('Framesmith upload did not leave awaiting_upload before timeout: ' . $this->formatFramesmithUploadDiagnostic($lastDiagnostic));
  }

  /**
   * Returns browser and Drupal-side upload progress in one diagnostic payload.
   *
   * The synchronous XHR is intentionally test-only. It uses the browser's
   * authenticated same-origin session, so it verifies the exact cookies and
   * origin shape used by the upload instead of trusting PHP-side state alone.
   *
   * @return array<string,mixed>
   *   Upload diagnostic state.
   */
  private function getFramesmithUploadDiagnosticState(): array {
    $diagnostic = $this->getSession()->evaluateScript(<<<'JS'
(function () {
  const state = window.__framesmithSmokeUpload || { calls: [] };
  const calls = Array.isArray(state.calls) ? state.calls : [];
  const taskId = String(window.__lastWhisperDrupalTaskId || '');
  let statusPayload = null;
  let statusError = '';
  if (taskId.length > 0) {
    try {
      const xhr = new XMLHttpRequest();
      xhr.open('GET', '/api/framesmith/transcription/status?task_id=' + encodeURIComponent(taskId), false);
      xhr.withCredentials = true;
      xhr.send(null);
      if (xhr.status >= 200 && xhr.status < 300) {
        statusPayload = JSON.parse(xhr.responseText || '{}');
      }
      else {
        statusError = 'status endpoint returned HTTP ' + xhr.status;
      }
    }
    catch (error) {
      statusError = error && error.message ? error.message : String(error);
    }
  }
  const task = statusPayload && statusPayload.task ? statusPayload.task : null;
  const progress = task && task.upload_progress ? task.upload_progress : null;
  return {
    taskId,
    statusText: document.querySelector('#videoSourceStatus')?.textContent || '',
    browserUploadCallCount: calls.length,
    browserCalls: calls.slice(-5),
    taskStatus: task ? String(task.status || '') : '',
    localAudioPath: task ? String(task.local_audio_path || '') : '',
    backendReceivedCount: progress ? Number(progress.received_count || 0) : 0,
    backendTotal: progress ? Number(progress.total || 0) : 0,
    backendComplete: progress ? Boolean(progress.complete) : false,
    uploadProgress: progress,
    statusError
  };
}())
JS);

    return is_array($diagnostic) ? $diagnostic : [];
  }

  /**
   * Returns the values that mean upload progress advanced.
   */
  private function framesmithUploadProgressSignature(array $diagnostic): string {
    return implode('|', [
      (string) ($diagnostic['taskId'] ?? ''),
      (string) ($diagnostic['browserUploadCallCount'] ?? 0),
      (string) ($diagnostic['backendReceivedCount'] ?? 0),
      (string) ($diagnostic['taskStatus'] ?? ''),
      (string) ($diagnostic['localAudioPath'] ?? ''),
    ]);
  }

  /**
   * Formats upload diagnostics for a failed smoke assertion.
   */
  private function formatFramesmithUploadDiagnostic(array $diagnostic): string {
    return json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '(unavailable)';
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
    $dropFirstChunkAttempts = $this->envInt(
      'FRAMESMITH_SMOKE_DROP_FIRST_UPLOAD_CHUNK_ATTEMPTS',
      $this->envFlag('FRAMESMITH_SMOKE_DROP_FIRST_UPLOAD_CHUNK') ? 1 : 0,
    );
    if ($dropFirstChunkAttempts > 0) {
      $droppedFirstChunk = 0;
      $retriedFirstChunk = 0;
      foreach ($calls as $call) {
        if (!is_array($call) || (int) ($call['index'] ?? -1) !== 0) {
          continue;
        }
        $retriedFirstChunk++;
        if (($call['simulatedDrop'] ?? FALSE) === TRUE) {
          $droppedFirstChunk++;
        }
      }
      $this->assertSame(
        $dropFirstChunkAttempts,
        $droppedFirstChunk,
        'Framesmith upload smoke did not simulate the requested first-chunk drops.',
      );
      $this->assertGreaterThan(
        $dropFirstChunkAttempts,
        $retriedFirstChunk,
        'Framesmith did not retry the first chunk after simulated network drops.',
      );
    }
    $this->assertGreaterThan(
      1,
      count($indices),
      'Framesmith upload smoke expected multiple chunk indexes.',
    );

    if ($dropFirstChunkAttempts > 0) {
      $this->assertSame(
        $dropFirstChunkAttempts,
        (int) ($state['simulatedDropCount'] ?? 0),
        'Framesmith upload smoke did not simulate the requested network drops.',
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
