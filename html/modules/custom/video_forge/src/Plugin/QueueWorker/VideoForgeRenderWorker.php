<?php

namespace Drupal\video_forge\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\video_forge\Service\RenderProcessor;

/**
 * Processes video rendering tasks.
 *
 * @QueueWorker(
 *   id = "video_forge_caption_rendering",
 *   title = @Translation("Video Caption Rendering"),
 *   cron = {"time" = 60}
 * )
 */
class VideoForgeRenderWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The render processor service.
   *
   * @var \Drupal\video_forge\Service\RenderProcessor
   */
  protected $renderProcessor;

  /**
   * Constructs a new VideoForgeRenderWorker object.
   *
   * @param \Drupal\video_forge\Service\RenderProcessor $render_processor
   *   The render processor service.
   */
  public function __construct(RenderProcessor $render_processor) {
    $this->renderProcessor = $render_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('video_forge.render_processor')
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

    // Call the render processor service.
    $this->renderProcessor->renderCaptions($media, $data['video_path'], $data['subtitle_path']);
  }
}

