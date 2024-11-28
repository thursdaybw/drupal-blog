<?php

namespace Drupal\video_forge\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\video_forge\Service\CaptionsProcessor;

/**
 * Processes video caption generation tasks.
 *
 * @QueueWorker(
 *   id = "video_forge_caption_generation",
 *   title = @Translation("Video Caption Generation"),
 *   cron = {"time" = 60}
 * ) */
class VideoForgeCaptionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The video processor service.
   *
   * @var \Drupal\video_forge\Service\VideoProcessor
   */
  protected $captionsProcessor;

  /**
   * Constructs a new VideoForgeCaptionWorker object.
   *
   * @param \Drupal\video_forge\Service\CaptionsProcessor $captions_processor
   *   The video processor service.
   */
  public function __construct(CaptionsProcessor $captions_processor) {
    $this->captionsProcessor = $captions_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('video_forge.captions_processor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Load the media entity.
    $media = \Drupal::entityTypeManager()->getStorage('media')->load($data['media_id']);
    if (!$media) {
      \Drupal::logger('video_forge')->error('Media entity not found for ID @id', ['@id' => $data['media_id']]);
      return;
    }

    // Run the caption processing.
    $this->captionsProcessor->generateCaptions($media, $data['video_path']);
  }
}

