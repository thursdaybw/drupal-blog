<?php
namespace Drupal\Tests\bevansbench_test\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\MobileTestBase;
use Symfony\Component\HttpFoundation\Request;

class GenerateCaptionsMobileTest extends MobileTestBase {

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

    $user = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => 'bevan']);
    $user = reset($user);
    $this->assertNotNull($user);

    $this->drupalLogin($user);

    // 2. Visit the video upload page in test mode (?test=1 enables UUID hook).
    $this->visit('/video-react?test=1');

    // 3. Wait for file input to appear.
    $this->assertSession()->waitForElementVisible('css', '#video-upload');

    // 4. Attach the test file.
    $this->getSession()->getPage()->attachFileToField('video-upload', '/fixtures/sample.mp4');

    // 5. Wait for upload status message.
    $this->assertSession()->waitForText('video upload complete', 10000);
    sleep(5);

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

