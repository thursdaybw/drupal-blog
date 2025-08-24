<?php
namespace Drupal\Tests\bevansbench_test\ExistingSiteJavascript;

use DrupalTest\QueueRunnerTrait\QueueRunnerTrait;
use thursdaybw\DttMultiDeviceTestBase\MobileTestBase;
use Symfony\Component\HttpFoundation\Request;

class GenerateCaptionsMobileTest extends MobileTestBase {

  use QueueRunnerTrait;

  public function testLoginLinkVisible() {
    $this->visit('/');
    $this->assertSession()->elementExists('css', 'nav#block-vani-account-menu a');
  }


  public function testAttachFileFromMountedVolume(): void {

    $user = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => 'bevan']);
    $user = reset($user);
    $this->assertNotNull($user);

    $this->drupalLogin($user);
    $username = $user->getAccountName();

    $this->visit('/video-react');

    $this->assertSession()->waitForElementVisible('css', 'input[type="file"]');

    // Attach the file directly to the file input — Selenium now works with real files
    $remotePath = '/fixtures/sample-race.mp4';
    //$remotePath = '/fixtures/sample.mp4';
    $this->getSession()->getPage()->attachFileToField('video-upload', $remotePath);

    // Wait for React to process the change
    $this->assertSession()->waitForElementVisible('css', 'video');
    $this->assertSession()->waitForElementVisible('css', 'p');
    $this->assertSession()->elementTextContains('css', 'p', 'Video upload complete');
    sleep(5);
  }

  public function testSuccessfulVideoUploadCreatesMedia(): void {

    $task_storage = \Drupal::entityTypeManager()->getStorage('video_forge_tasks');

    //$web_root_url = '';
    $web_root_url = 'https://2822a8d26205.ngrok-free.app';
    //$domain = 'https://www.bevansbench.com';

    if (empty($web_root_url)) {
      $user = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => 'bevan']);
      $user = reset($user);
      $this->assertNotNull($user);

      $this->drupalLogin($user);
    }
    else {
      $this->visit($web_root_url . '/user/login');

      if (preg_match('#https?://[a-z0-9]+\.ngrok(-free)?\.app#i', $web_root_url)) {
        $this->assertSession()->waitForElementVisible('css', 'button.ring-blue-600\/20');
        $this->getSession()->getPage()->pressButton('Visit Site');
      }

      $this->submitForm([
        'name' => 'admin',
        'pass' => 'LeiYoh6a',
      ], 'Log in');

      $this->assertSession()->addressEquals('/user/1');
    }

    $this->assertSession()->pageTextContains('Member for');

    // 2. Visit the video upload page in test mode (?test=1 enables UUID hook).
    if (empty($web_root_url)) {
      $this->visit('/video-react?test=1');
    }
    else {
      $this->visit($web_root_url . '/video-react?test=1');
    }

    // 3. Wait for file input to appear.
    $this->assertSession()->waitForElementVisible('css', '#video-upload');


    // 4. Attach the test file.
    fwrite(STDERR, "Attach video file.\n");
    //$this->getSession()->getPage()->attachFileToField('video-upload', '/fixtures/sample.mp4');
    //$this->getSession()->getPage()->attachFileToField('video-upload', '/fixtures/sample-200.mp4');
    $this->getSession()->getPage()->attachFileToField('video-upload', '/fixtures/sample-race.mp4');

    //fwrite(STDERR, "Waiting for upload complete status.\n");
    //$this->assertSession()->waitForText('video upload complete', 360000);

    $this->assertSession()->waitForElementVisible(
      'named',
      ['button', 'Generate Captions'],
      10000
    );
    fwrite(STDERR, "Click generate captions.\n");
    $this->getSession()->getPage()->pressButton('Generate Captions');

    $video_id = $this->getSession()->evaluateScript(
      "document.getElementById('video-id')?.dataset.uuid"
    );
    $this->assertNotEmpty($video_id, 'Extracted video_id from test DOM hook.');

    fwrite(STDERR, "Video ID: $video_id\n");

    /*
    fwrite(STDERR, "Wait for media to exist from video upload.\n");

    // Wait until media row shows up in DB directly.
    $connection = \Drupal::database();
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');
    $media_storage->resetCache();
    $media = [];
    $attempts = 0;

    while (empty($media) && $attempts < 30) {
      $media = $media_storage->loadByProperties([ 'bundle' => 'forge_video', 'name' => "Video $video_id", ]);
      $media = reset($media);

      if (empty($media)) {
        sleep(1);
        $attempts++;
      }
    }

    $this->assertNotEmpty($media, "Forge Video media row exists in DB (attempts=$attempts)");

    fwrite(STDERR, "Wait for task to exist from captions click.\n");
    // Wait until task is created.
    $task_storage = \Drupal::entityTypeManager()->getStorage('video_forge_tasks');
    $task = [];
    $attempts = 0;
    while (empty($task) && $attempts < 30) {
      $task = $task_storage->loadByProperties(['video_ref_id' => $video_id]);

      if (empty($task)) {
        sleep(1);
        $attempts++;
      }
    }
    $this->assertNotEmpty($task, "Task entity exists after upload (attempts=$attempts)");
    $task = reset($task);
    */
    /**
     * ⚠️ Important note:
     * In production, the upload request and the provision queue worker
     * always run in separate PHP processes. Each process starts with
     * a fresh entity cache, so the updated task entity is guaranteed
     * to be loaded from the database.
     *
     * In PHPUnit/Mink tests, both the upload and the queue runner
     * execute within the SAME PHP process. Drupal's entity storage
     * uses a static in-memory cache, which means the provision worker
     * can see a stale version of the task entity where `local_audio_file`
     * is still empty.
     *
     * To accurately simulate production behaviour, we must clear the
     * static cache for this task entity before running the provision queue.
     */

    /*
    $task_storage = \Drupal::entityTypeManager()->getStorage('video_forge_tasks');
    $task_storage->resetCache([$task->id()]);

    $linked_media_id = $task->get('video')->target_id ?? 'NULL';
    $this->assertNotEmpty($linked_media_id, "Task has video linked after upload (got $linked_media_id)");

    */

    fwrite(STDERR, "Wait for queue text in app\n");
    $this->assertSession()->waitForText('Waiting for server… (status: queued)', 10000);

    sleep(5);
    fwrite(STDERR, "Run provision job.\n");
    $this->waitForAndRunQueue('video_forge_provision');

    $this->assertSession()->waitForText('✅ Transcription complete!', 360000);

    fwrite(STDERR, "Transcription complete.\n");

    fwrite(STDERR, "Check task state.\n");

    // -------------------------------------------------------------
    // Existing entity assertions (in-memory entity).
    // -------------------------------------------------------------
    $task = $task_storage->loadByProperties(['video_ref_id' => $video_id]);
    $task = reset($task);
    $linked_media_id = $task->get('video')->target_id ?? 'NULL';
    $this->assertNotEmpty($linked_media_id, "Task has video linked (in-memory, got $linked_media_id)");
    $this->assertNotEmpty($task->get('field_transcript_json')->entity, 'Transcript JSON attached (in-memory)');
    $this->assertNotEmpty($task->get('subtitle_ass_file')->entity, 'Transcript ASS attached (in-memory)');

    // -------------------------------------------------------------
    // Direct DB check (bypasses entity cache).
    // -------------------------------------------------------------
    $connection = \Drupal::database();
    $row = $connection->select('video_forge_tasks', 't')
                      ->fields('t')
                      ->condition('video_ref_id', $video_id)
                      ->execute()
                      ->fetchAssoc();

    //fwrite(STDERR, "DB row for video_ref_id=$video_id: " . print_r($row, TRUE) . "\n");

    $this->assertNotEmpty(
      $row['video'] ?? NULL,
      "Direct DB check: video__target_id is populated (got " . ($row['video__target_id'] ?? 'NULL') . ")"
    );

    // -------------------------------------------------------------
    // Log assertions (check both before and after save messages).
    // -------------------------------------------------------------
    $logs = $connection->select('watchdog', 'w')
                       ->fields('w', ['message'])
                       ->condition('type', 'video_forge')
                       ->orderBy('wid', 'DESC')
                       ->range(0, 50) // grab enough recent entries
                       ->execute()
                       ->fetchCol();

    //fwrite(STDERR, "Recent video_forge logs:\n" . implode("\n", $logs) . "\n");

    $found_before_save = FALSE;
    $found_after_save  = FALSE;

    foreach ($logs as $log) {
      if (str_contains($log, "Before save transcript task {$task->uuid()}")) {
        $found_before_save = TRUE;
      }
      if (str_contains($log, "After save transcript task {$task->uuid()}")) {
        $found_after_save = TRUE;
      }
    }

    $this->assertTrue(
      $found_before_save,
      "Saw 'Before save transcript task {$task->uuid()}' in watchdog log"
    );
    $this->assertTrue(
      $found_after_save,
      "Saw 'After save transcript task {$task->uuid()}' in watchdog log"
    );


    $this->assertNotEmpty($task->get('field_transcript_json')->entity, 'Transcript JSON attached');
    $this->assertNotEmpty($task->get('subtitle_ass_file')->entity, 'Transcript ASS attached');

    $this->getSession()->getPage()->pressButton('Render Final Video');

    $this->assertSession()->waitForText('render_queued', 360000);

    $this->waitForAndRunQueue('video_forge_caption_rendering');


    $this->assertSession()->waitForText('Render complete!', 360000);


    // Inject a button into the page.
    $this->getSession()->executeScript("
  var btn = document.createElement('button');
  btn.id = 'manual-stop';
  btn.textContent = '✅ Finish Test';
  btn.style.cssText = 'position:fixed;top:10px;right:10px;z-index:99999;padding:10px;font-size:16px;';
  btn.dataset.done = '0';
  btn.onclick = function() {
     btn.textContent = 'Test Finished';
  };
  document.body.appendChild(btn);
");

// Now wait until the button's data attribute changes (i.e., clicked).
$this->assertSession()->waitForText('Test Finished', 360000);

  }

  /**
   * Waits for a Drupal queue to have at least one item and then runs it.
   *
   * @param string $queue_name
   *   The machine name of the queue (e.g. 'video_forge_provision').
   * @param int $maxAttempts
   *   Maximum polling attempts.
   * @param int $sleepSeconds
   *   Seconds to sleep between attempts.
   */
  protected function waitForAndRunQueue(string $queue_name, int $maxAttempts = 3, int $sleepSeconds = 5): void {
    $attempt = 0;
    $count = 0;

    do {
      $queue = \Drupal::service('queue')->get($queue_name);
      $count = $queue->numberOfItems();

      if (!is_int($count)) {
        throw new \RuntimeException(
          sprintf('%s queue numberOfItems() returned non-int: %s', $queue_name, print_r($count, true))
        );
      }

      if ($count > 0) {
        // Items found.
        break;
      }

      $attempt++;
      if ($attempt < $maxAttempts) {
        sleep($sleepSeconds);
      }
    } while ($attempt < $maxAttempts);

    $this->assertGreaterThan(0, $count, sprintf('Queue "%s" has items', $queue_name));

    // Optional: inspect queue rows if needed.
    $connection = \Drupal::service('database');
    $items = $connection->select('queue', 'q')
                        ->fields('q')
                        ->condition('name', $queue_name)
                        ->execute()
                        ->fetchAll();

    // Run the queue via Drush in a new PHP process.
    $cmd = 'drush queue:run ' . escapeshellarg($queue_name);
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);

    if ($return_var !== 0) {
      $this->fail(sprintf(
        'Drush command "%s" failed with exit code %d. Output: %s',
        $cmd,
        $return_var,
        implode("\n", $output)
      ));
    }
  }


  public function testCaptionsPageAnonymousShowsStatusMessage(): void {
    $this->visit('/video-react');

    $this->assertSession()->waitForElementVisible('css', 'p');
    $this->assertSession()->elementTextContains('css', 'p', 'Status: Anonymous or error');

  }

  protected function tearDown(): void {
    if (isset($this->driver)) {
      try {
        $this->driver->quit(); // clean shutdown
      } catch (\Throwable $e) {
        // swallow it silently
      }
    }
    parent::tearDown();
  }


}

