<?php

namespace Drupal\video_forge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Handles rendering captions into a video.
 */
final class RenderCaptionsController extends ControllerBase {

  /**
   * Renders captions into the given media entity's video.
   */
  public function render(MediaInterface $media) {
    if ($media->bundle() !== 'forge_video') {
      $this->messenger()->addError($this->t('This media item is not a video.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    $subtitle_file = $media->get('field_subtitle_file_ass')->entity;
    if (!$subtitle_file) {
      $this->messenger()->addError($this->t('No subtitles found for this video.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    $subtitle_path = \Drupal::service('file_system')->realpath($subtitle_file->getFileUri());
    $video_file = $media->get('field_media_video_file')->entity;
    $video_path = \Drupal::service('file_system')->realpath($video_file->getFileUri());

    // Add the rendering task to the queue.
    $queue = \Drupal::service('queue')->get('video_forge_caption_rendering');
    $queue->createItem([
      'media_id' => $media->id(),
      'video_path' => $video_path,
      'subtitle_path' => $subtitle_path,
    ]);

    // Update the media processing state.
    $media->set('field_processing_state', 'render_queued')->save();

    $this->messenger()->addMessage($this->t('Caption rendering has been queued.'));
    return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
  }
}

