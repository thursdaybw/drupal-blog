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
    $remotePath = '/fixtures/sample.mp4';
    $this->getSession()->getPage()->attachFileToField('video-upload', $remotePath);

    // Wait for React to process the change
    $this->assertSession()->waitForElementVisible('css', 'video');
    $this->assertSession()->waitForElementVisible('css', 'p');
    $this->assertSession()->elementTextContains('css', 'p', 'Video upload complete');
    sleep(5);
  }

  public function testSuccessfulVideoUploadCreatesMedia(): void {
/*
    $user = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => 'bevan']);
    $user = reset($user);
    $this->assertNotNull($user);

    $this->drupalLogin($user);
 */
    $this->visit('https://www.bevansbench.com/user/login');

    $this->submitForm([
      'name' => 'admin',
      'pass' => 'LeiYoh6a',
    ], 'Log in');

    $this->assertSession()->addressEquals('/user/1');
    $this->assertSession()->pageTextContains('Member for');

    // 2. Visit the video upload page in test mode (?test=1 enables UUID hook).
    //$this->visit('/video-react?test=1');
    $this->visit('https://www.bevansbench.com/video-react?test=1');

    // 3. Wait for file input to appear.
    $this->assertSession()->waitForElementVisible('css', '#video-upload');

    // 4. Attach the test file.
    $this->getSession()->getPage()->attachFileToField('video-upload', '/fixtures/sample.mp4');

    // 5. Wait for upload status message.
    $this->assertSession()->waitForText('video upload complete', 10000);

    $this->assertSession()->waitForText('Upload Progress: 100%', 10000);

    // 6. Extract the generated video_id from the React DOM test hook.
    $video_id = $this->getSession()->evaluateScript("document.getElementById('video-id')?.dataset.uuid");
    $this->assertNotEmpty($video_id, 'Extracted video_id from test DOM hook.');

    // 7. Assert that a forge_video media entity was created with correct name.
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');
    $media = $media_storage->loadByProperties([
      'bundle' => 'forge_video',
      'name' => "Video $video_id",
    ]);
    $this->assertNotEmpty($media, 'Forge Video media entity created.');
    $media = reset($media);

    // 8. Assert the attached file exists at the expected location.
    $file = $media->get('field_media_video_file')->entity;
    $this->assertInstanceOf(\Drupal\file\FileInterface::class, $file);
    $this->assertEquals("public://video_forge/uploads/$video_id.mp4", $file->getFileUri());

    // 9. Optionally check for video_forge_task linkage.
    $task_storage = \Drupal::entityTypeManager()->getStorage('video_forge_tasks');
    $task = $task_storage->loadByProperties(['video_ref_id' => $video_id]);
    if ($task = reset($task)) {
      $linked_media = $task->get('field_media_video_file')->entity;
      $this->assertEquals($media->id(), $linked_media->id(), 'Media entity linked to video_forge_task.');
    }


    $this->getSession()->getPage()->pressButton('Generate Captions');

    $this->assertSession()->waitForText('Waiting for server… (status: queued)', 10000);


    $queue = \Drupal::service('queue')->get('video_forge_provision');

    $count = $queue->numberOfItems();

    if (!is_int($count)) {
      throw new \RuntimeException('numberOfItems() returned non-int: ' . print_r($count, true));
    }

    $this->assertGreaterThan(0, $count, 'Queue has items');

    $connection = \Drupal::service('database');
    $items = $connection->select('queue', 'q')
                        ->fields('q')
                        ->condition('name', 'video_forge_provision')
                        ->execute()
                        ->fetchAll();
    //throw new \RuntimeException('Queue contains ' . count($items) . ' items');
    /*
    foreach ($items as $item) {
      throw new \RuntimeException(print_r($item, true));
    }
     */

    $this->runQueue('video_forge_provision');
    //$this->exec('drush queue-run video_forge_provision');

    $this->assertSession()->waitForText('✅ Transcription complete!', 60000);
    sleep(60);
    //$this->assertSession()->waitForText('✅ Render complete!', 120000);
    //$this->runQueue('video_forge_caption_generation');
    //$this->runQueue('video_forge_caption_rendering');

    // 🔄 10. Clean up: delete media, file, and task (if created).
    $media->delete();
    $file->delete();
    if (!empty($task)) {
      $task->delete();
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

