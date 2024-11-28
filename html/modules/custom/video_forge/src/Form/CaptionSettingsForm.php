<?php

namespace Drupal\video_forge\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\media\MediaInterface;

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

	  $module_path = \Drupal::service('module_handler')->getModule('video_forge')->getPath();
	  $php_script = DRUPAL_ROOT . '/' . $module_path . '/captions.php';

	  // Build the command with properly escaped arguments.
	  $command = sprintf(
		  'php %s --style=%s %s %s',
		  escapeshellarg($php_script), // Escape the script path for safety.
		  escapeshellarg($style), // Escape the style argument.
		  escapeshellarg(\Drupal::service('file_system')->realpath($json_file)), // Escape the JSON file path.
		  escapeshellarg(\Drupal::service('file_system')->realpath($ass_file)) // Escape the ASS file path.
	  );

	  // Execute the command and capture output and return code.
	  exec($command, $output, $return_var);

	  // Log the executed command, output, and return value for debugging.
	  \Drupal::logger('video_forge')->info('Captions.php Command: @command', ['@command' => $command]);
	  \Drupal::logger('video_forge')->info('Captions.php Output: @output', ['@output' => implode("\n", $output)]);
	  \Drupal::logger('video_forge')->info('Captions.php Return Code: @return', ['@return' => $return_var]);

	  // Check the result of the command execution.
	  if ($return_var !== 0) {
		  $this->messenger()->addError($this->t('Failed to generate ASS file.'));
	  } else {
		  $this->messenger()->addMessage($this->t('ASS file successfully generated with the "%style" style.', ['%style' => $style]));
	  }

	  // Redirect back to the media view.
	  $form_state->setRedirect('entity.media.canonical', ['media' => $this->media->id()]);
  }



}

