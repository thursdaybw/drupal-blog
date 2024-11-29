<?php

namespace Drupal\video_forge\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\media\MediaInterface;
use Drupal\video_forge\Subtitle\AssSubtitleGenerator;

/**
 * Provides a form for adjusting caption settings.
 */
class CaptionSettingsForm extends FormBase {

  /**
   * The media entity.
   *
   * @var \Drupal\media\MediaInterface
   */
  protected $media;

  /**
   * Constructs the form.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   */
  public function __construct(MediaInterface $media) {
    $this->media = $media;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match')->getParameter('media')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'video_forge_caption_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

	  // Dropdown to select a style.
	  $form['style'] = [
		  '#type' => 'select',
		  '#title' => $this->t('Caption Style'),
		  '#default_value' => 'GreenAndGold', // Set the default style.
		  '#description' => $this->t('Choose the style for the captions.'),
		  '#options' => [
			  'GreenAndGold' => $this->t('Green and Gold'),
			  'MrBeast' => $this->t('Mr Beast'),
			  'NeonGlow' => $this->t('Neon Glow'),
			  'BoldShadow' => $this->t('Bold Shadow'),
			  'ClassicBlue' => $this->t('Classic Blue'),
			  'KaraokeClassic' => $this->t('Karaoke Classic'),
			  'PlainStyle' => $this->t('Plain'),
		  ],
	  ];

	  $form['actions']['#type'] = 'actions';
	  $form['actions']['submit'] = [
		  '#type' => 'submit',
		  '#value' => $this->t('Generate ASS File'),
	  ];

	  return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
	  $style = $form_state->getValue('style');

	  // Generate the .ass file from the .json file.
	  $json_file = $this->media->get('field_json_transcript_file')->entity->getFileUri();
	  $ass_file = str_replace('.json', '.ass', $json_file);

	  $this->process_subtitles($json_file, $ass_file, $style);

	  // Redirect back to the media view.
	  $form_state->setRedirect('entity.media.canonical', ['media' => $this->media->id()]);
  }

  private function process_subtitles($inputJson, $outputAss, $style = 'GreenAndGold') {
	  $generator = new AssSubtitleGenerator();
	  $generator->generateAssFromJson($inputJson, $outputAss, $style);
  }

}

