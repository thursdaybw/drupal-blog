<?php

declare(strict_types=1);

namespace Drupal\video_forge\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;
use Drupal\video_forge\Entity\CaptionStyle;

/**
 * Caption settings form for media entities.
 */
final class CaptionSettingsForm extends FormBase {
  use CaptionFormTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'video_forge_caption_settings_form';
  }

  /**
   * Builds the form for caption settings.
   */
  public function buildForm(array $form, FormStateInterface $form_state, MediaInterface $media = NULL): array {
    // Load existing caption settings from the media entity.
    $selected_style = $media->get('field_caption_style')->value ?? 'custom';
    $overrides = $media->get('field_caption_overrides')->value
      ? json_decode($media->get('field_caption_overrides')->value, TRUE)
      : [];

    // Fetch all available caption styles.
    $styles = CaptionStyle::loadMultiple();
    $style_options = array_map(fn($style) => $style->label(), $styles);
    $style_options['custom'] = $this->t('Custom');

    // Style selection dropdown.
    $form['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Caption Style'),
      '#default_value' => $selected_style,
      '#options' => $style_options,
      '#ajax' => [
        'callback' => '::updateAdvancedSettings',
        'wrapper' => 'advanced-settings-wrapper',
      ],
      '#description' => $this->t('Choose an existing caption style or select "Custom" to create a unique configuration.'),
    ];

    // Advanced settings section.
    $form['advanced_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => TRUE,
      '#prefix' => '<div id="advanced-settings-wrapper">',
      '#suffix' => '</div>',
    ];

    // Load fields from the selected style or overrides.
    if ($selected_style !== 'custom' && isset($styles[$selected_style])) {
      $selected_entity = $styles[$selected_style];
      $default_values = $selected_entity->toArray();
    } else {
      $default_values = $overrides;
    }

    // Add common fields and highlights to the advanced settings section.
    $form['advanced_settings'] += $this->buildCommonFields($default_values);
    $form['advanced_settings'] += $this->buildHighlightFieldsets($default_values);

    // Action buttons.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Settings'),
    ];
    $form['actions']['save_as_new'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save as New Style'),
      '#submit' => ['::saveAsNewStyle'],
    ];

    return $form;
  }

  /**
   * AJAX callback to dynamically update the advanced settings section.
   */
  public function updateAdvancedSettings(array &$form, FormStateInterface $form_state): array {
    return $form['advanced_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save the selected style and any customizations.
    $style = $form_state->getValue('style');
    $overrides = [];

    if ($style === 'custom') {
      $overrides = array_filter($form_state->getValue('advanced_settings'));
    }

    /** @var \Drupal\media\MediaInterface $media */
    $media = $form_state->getBuildInfo()['args'][0];

    $media->set('field_caption_style', $style);
    $media->set('field_caption_overrides', json_encode($overrides));
    $media->save();

    $this->messenger()->addStatus($this->t('Caption settings have been saved.'));
  }

  /**
   * Custom submission handler to save as a new style.
   */
  public function saveAsNewStyle(array &$form, FormStateInterface $form_state): void {
    // Create a new caption style entity from the current advanced settings.
    $overrides = array_filter($form_state->getValue('advanced_settings'));
    $label = $this->t('Custom Style') . ' ' . time();

    $new_style = CaptionStyle::create([
      'label' => $label,
      'id' => strtolower(str_replace(' ', '_', $label)),
    ]);
    $this->saveFields($form_state, $new_style);
    $new_style->save();

    $this->messenger()->addStatus($this->t('New caption style %label has been saved.', ['%label' => $label]));
  }
}

