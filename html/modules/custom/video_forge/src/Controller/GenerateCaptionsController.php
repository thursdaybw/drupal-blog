<?php

declare(strict_types=1);

namespace Drupal\video_forge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Returns responses for Video Forge routes.
 */
final class GenerateCaptionsController extends ControllerBase {

  public function generate(MediaInterface $media) {
    // Ensure the media is of the correct bundle.
    \Drupal::logger('video_forge')->debug('Bundle value: "@bundle"', ['@bundle' => $media->bundle()]);
	  
    if ($media->bundle() !== 'forge_video') {
      $this->messenger()->addError($this->t('what This media item is not a video.' . $media->bundle()));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    // Get the video file.
    $video_file = $media->get('field_media_video_file')->entity;
    if (!$video_file) {
      $this->messenger()->addError($this->t('No video file found.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    $video_path = \Drupal::service('file_system')->realpath($video_file->getFileUri());

    // Trigger processing.
    $this->processVideo($media, $video_path);

    $this->messenger()->addMessage($this->t('Captions are being generated.'));
    return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
  }

  private function processVideo(MediaInterface $media, string $video_path): void {
    // Whisper and FFmpeg processing logic here...
    // Save generated artifacts as managed files.
    // Update Media entity fields with artifacts and status.

    // Example for updating JSON artifact:
    $json_file = str_replace('.mp4', '.json', $video_path);
    if (file_exists($json_file)) {
      $json_file_uri = 'public://videos/' . basename($json_file);
      \Drupal::service('file_system')->copy($json_file, $json_file_uri);

      $json_file_entity = \Drupal\file\Entity\File::create([
        'uri' => $json_file_uri,
        'status' => 1,
        'uid' => \Drupal::currentUser()->id(),
      ]);
      $json_file_entity->save();

      $media->set('field_json_artifact', ['target_id' => $json_file_entity->id()]);
    }

    // Update status.
    $media->set('field_processing_state', 'completed');
    $media->save();
  }


  /**
   * Builds the response.
   */
  public function __invoke(): array {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

}
