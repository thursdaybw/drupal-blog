<?php

namespace Drupal\video_forge\Service;

use Drupal\media\MediaInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Handles rendering captions into videos.
 */
class RenderProcessor {

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
   * The configuration service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs a new RenderProcessor object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('video_forge');
    $this->config = $config_factory->get('video_forge.settings');
  }

  /**
   * Renders captions into a video using FFmpeg.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to process.
   * @param string $video_path
   *   The absolute path to the video file.
   * @param string $subtitle_path
   *   The absolute path to the subtitle file.
   */
  public function renderCaptions(MediaInterface $media, string $video_path, string $subtitle_path): void {
    $output_video = str_replace('.mp4', '_with_captions.mp4', $video_path);

    // Run the FFmpeg command to burn subtitles into the video.
    $ffmpeg_path = $this->config->get('ffmpeg_path');
    $ffmpeg_command = "$ffmpeg_path -i \"$video_path\" -vf subtitles=\"$subtitle_path\" -c:a copy \"$output_video\"";
    exec($ffmpeg_command, $output, $return_var);

    $this->logCommand('FFmpeg', $ffmpeg_command, $output, $return_var);

    if ($return_var !== 0) {
      $this->logger->error('FFmpeg failed to render captions for video: @video', ['@video' => $video_path]);
      $media->set('field_processing_state', 'failed')->save();
      return;
    }

    // Save the rendered video as a managed file.
    $this->saveArtifact($media, $output_video, 'field_captioned_video');

    // Update the media entity's status.
    $media->set('field_processing_state', 'rendered')->save();
  }

  /**
   * Logs the command execution results.
   *
   * @param string $name
   *   The name of the command being executed.
   * @param string $command
   *   The full command string.
   * @param array $output
   *   The output of the command.
   * @param int $return_var
   *   The return value of the command.
   */
  private function logCommand(string $name, string $command, array $output, int $return_var): void {
    $this->logger->info('@name Command: @command', ['@name' => $name, '@command' => $command]);
    $this->logger->info('Output: @output', ['@output' => implode("\n", $output)]);
    $this->logger->info('Return code: @code', ['@code' => $return_var]);
  }

  /**
   * Saves a generated artifact as a managed file.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param string $file_path
   *   The path to the generated file.
   * @param string $field_name
   *   The name of the media field to attach the file to.
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

