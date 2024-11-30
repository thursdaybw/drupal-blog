<?php

namespace Drupal\video_forge\Service;

use Drupal\media\MediaInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\video_forge\Subtitle\AssSubtitleGenerator;

/**
 * Handles caption generation logic.
 */
class CaptionsProcessor {

  protected $fileSystem;
  protected $logger;
  protected $config;

  public function __construct(FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('video_forge');
    $this->config = $config_factory->get('video_forge.settings');
  }

  public function generateCaptions(MediaInterface $media, string $video_path): void {
    $output_dir = dirname($video_path);
    $json_file = str_replace('.mp4', '.json', $video_path);
    $ass_file = str_replace('.mp4', '.ass', $video_path);

    // Step 1: Generate JSON using Whisper.
    $whisper_command = "{$this->config->get('whisper_path')} --model medium -f json \"$video_path\" --output_dir \"$output_dir\" --word_timestamps True";
    exec($whisper_command, $output, $return_var);
    if ($return_var !== 0) {
      $this->logger->error('Whisper command failed.');
      return;
    }

    $this->process_subtitles($json_file, $ass_file);

    // Attach generated files to media.
    $this->attachFile($media, $json_file, 'field_json_transcript_file');
    $this->attachFile($media, $ass_file, 'field_subtitle_file_ass');

    // Update the media status.
    $media->set('field_processing_state', 'completed')->save();
  }

  private function attachFile(MediaInterface $media, string $file_path, string $field_name): void {
    if (!file_exists($file_path)) {
      return;
    }

    $public_path = $this->fileSystem->realpath('public://');
    $file_uri = strpos($file_path, $public_path) === 0
      ? 'public://' . substr($file_path, strlen($public_path) + 1)
      : NULL;

    if (!$file_uri) {
      $this->logger->error('File not in public system: @path', ['@path' => $file_path]);
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

    private function process_subtitles($inputJson, $outputAss, $style = 'GreenAndGold') {
          $generator = new AssSubtitleGenerator();
          $generator->generateAssFromJson($inputJson, $outputAss, $style);
  }


}

