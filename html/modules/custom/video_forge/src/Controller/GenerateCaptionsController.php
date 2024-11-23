<?php

declare(strict_types=1);

namespace Drupal\video_forge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles caption generation for videos.
 */
final class GenerateCaptionsController extends ControllerBase {
  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  private $streamWrapperManager;

  /**
   * Constructs a new GenerateCaptionsController object.
   *
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager service.
   */
  public function __construct(StreamWrapperManagerInterface $stream_wrapper_manager) {
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('stream_wrapper_manager')
    );
  }

  /**
   * Generate captions for the given media entity.
   */
  public function generate(MediaInterface $media) {
    // Ensure the media is of the correct bundle.
    if ($media->bundle() !== 'forge_video') {
      $this->messenger()->addError($this->t('This media item is not a video.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    // Get the video file.
    $video_file = $media->get('field_media_video_file')->entity;
    if (!$video_file) {
      $this->messenger()->addError($this->t('No video file found.'));
      return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
    }

    $video_path = \Drupal::service('file_system')->realpath($video_file->getFileUri());

    // Process the video.
    $this->processVideo($media, $video_path);

    // Inform the user.
    $this->messenger()->addMessage($this->t('Captions have been generated.'));
    return new RedirectResponse(Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString());
  }

  /**
   * Process the video file to generate captions and artifacts.
   */
  private function processVideo(MediaInterface $media, string $video_path): void {
    // Define paths for generated files.
    $output_dir = dirname($video_path);
    $json_file = str_replace('.mp4', '.json', $video_path);
    $ass_file = str_replace('.mp4', '.ass', $video_path);
    $output_video = str_replace('.mp4', '_with_captions.mp4', $video_path);

    // Step 1: Generate JSON using Whisper.
    $whisper_command = "/home/bevan/.local/bin/whisper --model medium -f json \"$video_path\" --output_dir \"$output_dir\" --word_timestamps True";
    exec($whisper_command, $output, $return_var);

    // Log the command and its output
    \Drupal::logger('video_forge')->info('Whisper Command: @command', ['@command' => $whisper_command]);
    \Drupal::logger('video_forge')->info('Output: @output', ['@output' => implode("\n", $output)]);
    \Drupal::logger('video_forge')->info('Return code: @code', ['@code' => $return_var]);

    if ($return_var !== 0) {
      $this->messenger()->addError($this->t('Whisper failed to process the video.'));
      return;
    }

    // Step 2: Generate ASS file.
    $module_path = \Drupal::service('module_handler')->getModule('video_forge')->getPath();
    $php_script = DRUPAL_ROOT . '/' . $module_path . '/captions.php';
    $ass_command = "php \"$php_script\" \"$json_file\" \"$ass_file\"";
    exec($ass_command, $output, $return_var);

    // Log the command and its output
    \Drupal::logger('video_forge')->info('ASS Command: @command', ['@command' => $ass_command]);
    \Drupal::logger('video_forge')->info('Output: @output', ['@output' => implode("\n", $output)]);
    \Drupal::logger('video_forge')->info('Return code: @code', ['@code' => $return_var]);


    if ($return_var !== 0) {
      $this->messenger()->addError($this->t('Failed to generate ASS captions.'));
      return;
    }

    // Step 3: Render captions into the video using FFmpeg.
    $ffmpeg_command = "ffmpeg -i \"$video_path\" -vf subtitles=\"$ass_file\" -c:a copy \"$output_video\"";
    exec($ffmpeg_command, $output, $return_var);

    // Log the command and its output
    \Drupal::logger('video_forge')->info('FFMPEG Command: @command', ['@command' => $ffmpeg_command]);
    \Drupal::logger('video_forge')->info('Output: @output', ['@output' => implode("\n", $output)]);
    \Drupal::logger('video_forge')->info('Return code: @code', ['@code' => $return_var]);

    if ($return_var !== 0) {
      $this->messenger()->addError($this->t('Failed to render captions into the video.'));
      return;
    }

    // Save generated artifacts as managed files.
    $this->saveArtifact($media, $json_file, 'field_json_transcript_file');
    $this->saveArtifact($media, $ass_file, 'field_subtitle_file_ass');
    $this->saveArtifact($media, $output_video, 'field_captioned_video');

    // Update the media status.
    $media->set('field_processing_state', 'completed');
    $media->save();
  }

  /**
 * Save an artifact as a managed file and associate it with the media entity.
 */
private function saveArtifact(MediaInterface $media, string $file_path, string $field_name): void {
  if (!file_exists($file_path)) {
    return;
  }

  // Get the base directory of the public file system.
  $file_system = \Drupal::service('file_system');
  $public_path = $file_system->realpath('public://');

  // Strip the public file system's base path and prepend the 'public://' scheme.
  if (strpos($file_path, $public_path) === 0) {
    $file_uri = 'public://' . substr($file_path, strlen($public_path) + 1);
  } else {
    // Handle error if the file is not in the public file system.
    \Drupal::logger('video_forge')->error('File path is not in the public file system: @path', ['@path' => $file_path]);
    return;
  }

  // Create the managed file entity.
  $file_entity = \Drupal\file\Entity\File::create([
    'uri' => $file_uri,
    'status' => 1, // Permanent file.
    'uid' => \Drupal::currentUser()->id(),
  ]);
  $file_entity->save();

  // Associate the file with the media entity.
  $media->set($field_name, [
    'target_id' => $file_entity->id(),
    'display' => 1,
  ]);
}



}

