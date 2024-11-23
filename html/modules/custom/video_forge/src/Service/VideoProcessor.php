<?php

namespace Drupal\video_forge\Service;

use Drupal\media\MediaInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Handles video processing logic.
 */
class VideoProcessor {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new VideoProcessor object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('video_forge');
  }

  /**
   * Processes the video and generates captions.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to process.
   * @param string $video_path
   *   The absolute path to the video file.
   */
  public function processVideo(MediaInterface $media, string $video_path): void {
    // Define paths for generated files.
    $output_dir = dirname($video_path);
    $json_file = str_replace('.mp4', '.json', $video_path);
    $ass_file = str_replace('.mp4', '.ass', $video_path);
    $output_video = str_replace('.mp4', '_with_captions.mp4', $video_path);


    // Step 1: Generate JSON using Whisper.
    $whisper_path = $this->config->get('whisper_path');
    $whisper_command = "$whisper_path --model medium -f json \"$video_path\" --output_dir \"$output_dir\" --word_timestamps True";
    exec($whisper_command, $output, $return_var);

    $this->logCommand('Whisper', $whisper_command, $output, $return_var);
    if ($return_var !== 0) {
      $media->set('field_processing_state', 'failed')->save();
      return;
    }

    // Step 2: Generate ASS file.
    $module_path = \Drupal::service('module_handler')->getModule('video_forge')->getPath();
    $php_script = DRUPAL_ROOT . '/' . $module_path . '/captions.php';
    $ass_command = "php \"$php_script\" \"$json_file\" \"$ass_file\"";
    exec($ass_command, $output, $return_var);

    $this->logCommand('ASS', $ass_command, $output, $return_var);
    if ($return_var !== 0) {
      $media->set('field_processing_state', 'failed')->save();
      return;
    }

    // Step 3: Render captions into the video using FFmpeg.
    $ffmpeg_path = $this->config->get('ffmpeg_path');
    $ffmpeg_command = "$ffmpeg_path -i \"$video_path\" -vf subtitles=\"$ass_file\" -c:a copy \"$output_video\"";
    exec($ffmpeg_command, $output, $return_var);

    $this->logCommand('FFmpeg', $ffmpeg_command, $output, $return_var);
    if ($return_var !== 0) {
      $media->set('field_processing_state', 'failed')->save();
      return;
    }

    // Save generated artifacts as managed files.
    $this->saveArtifact($media, $json_file, 'field_json_transcript_file');
    $this->saveArtifact($media, $ass_file, 'field_subtitle_file_ass');
    $this->saveArtifact($media, $output_video, 'field_captioned_video');

    // Update the media status.
    $media->set('field_processing_state', 'completed')->save();
  }

  /**
   * Logs the command execution results.
   */
  private function logCommand(string $name, string $command, array $output, int $return_var): void {
    $this->logger->info('@name Command: @command', ['@name' => $name, '@command' => $command]);
    $this->logger->info('Output: @output', ['@output' => implode("\n", $output)]);
    $this->logger->info('Return code: @code', ['@code' => $return_var]);
  }

  /**
   * Saves a generated artifact as a managed file.
   */
  private function saveArtifact(MediaInterface $media, string $file_path, string $field_name): void {
    if (!file_exists($file_path)) {
      return;
    }

    $public_path = $this->fileSystem->realpath('public://');
    $file_uri = strpos($file_path, $public_path) === 0
      ? 'public://' . substr($file_path, strlen($public_path) + 1)
      : NULL;

    if (!$file_uri) {
      $this->logger->error('File path is not in the public file system: @path', ['@path' => $file_path]);
      return;
    }

    $file_entity = \Drupal\file\Entity\File::create([
      'uri' => $file_uri,
      'status' => 1,
      'uid' => \Drupal::currentUser()->id(),
    ]);
    $file_entity->save();

    $media->set($field_name, ['target_id' => $file_entity->id()]);
  }
}

