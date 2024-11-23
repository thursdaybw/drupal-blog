<?php

namespace Drupal\video_forge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Handles caption generation for videos.
 */
final class GenerateCaptionsController extends ControllerBase {

  /**
   * Generates captions for the given media entity.
   */
  public function generate(MediaInterface $media) {
    if ($media->bundle() !== 'forge_video') {
      $this->messenger()->addError($this->t('This media item is not a video.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    $video_file = $media->get('field_media_video_file')->entity;
    if (!$video_file) {
      $this->messenger()->addError($this->t('No video file found.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    $video_path = \Drupal::service('file_system')->realpath($video_file->getFileUri());

    // Add to the queue.
    $queue = \Drupal::service('queue')->get('video_forge_caption_generation');
    $queue->createItem([
      'media_id' => $media->id(),
      'video_path' => $video_path,
    ]);

    $media->set('field_processing_state', 'queued')->save();

    $this->messenger()->addMessage($this->t('Captions generation has been queued.'));
    return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
  }
}

