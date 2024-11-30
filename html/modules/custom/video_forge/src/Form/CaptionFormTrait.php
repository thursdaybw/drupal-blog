<?php

declare(strict_types=1);

namespace Drupal\video_forge\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Provides shared logic for caption forms.
 */
trait CaptionFormTrait {

  /**
   * Builds common fields for caption styles.
   */
  protected function buildCommonFields(array $default_values = []): array {
    return [
      'fontName' => [
        '#type' => 'textfield',
        '#title' => $this->t('Font Name'),
        '#default_value' => $default_values['fontName'] ?? 'Arial',
        '#required' => TRUE,
      ],
      'fontSize' => [
        '#type' => 'number',
        '#title' => $this->t('Font Size'),
        '#default_value' => $default_values['fontSize'] ?? 70,
        '#min' => 10,
        '#max' => 100,
        '#required' => TRUE,
      ],
      'primaryColour' => [
        '#type' => 'textfield',
        '#title' => $this->t('Primary Colour'),
        '#default_value' => $default_values['primaryColour'] ?? '&H00FFFFFF',
      ],
      'secondaryColour' => [
        '#type' => 'textfield',
        '#title' => $this->t('Secondary Colour'),
        '#default_value' => $default_values['secondaryColour'] ?? '&H00000000',
      ],
      'outlineColour' => [
        '#type' => 'textfield',
        '#title' => $this->t('Outline Colour'),
        '#default_value' => $default_values['outlineColour'] ?? '&H00000000',
      ],
      'bold' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Bold'),
        '#default_value' => $default_values['bold'] ?? 0,
      ],
      'italic' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Italic'),
        '#default_value' => $default_values['italic'] ?? 0,
      ],
      'underline' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Underline'),
        '#default_value' => $default_values['underline'] ?? 0,
      ],
      'scaleX' => [
        '#type' => 'number',
        '#title' => $this->t('Scale X'),
        '#default_value' => $default_values['scaleX'] ?? 100,
      ],
      'scaleY' => [
        '#type' => 'number',
        '#title' => $this->t('Scale Y'),
        '#default_value' => $default_values['scaleY'] ?? 100,
      ],
      'alignment' => [
        '#type' => 'select',
        '#title' => $this->t('Alignment'),
        '#options' => [
          1 => $this->t('Left'),
          2 => $this->t('Center'),
          3 => $this->t('Right'),
        ],
        '#default_value' => $default_values['alignment'] ?? 2,
      ],
    ];
  }

  /**
   * Builds highlight fields for a given title and default values.
   */
  protected function buildHighlightFields(string $title, array $default_values = []): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t($title),
      '#tree' => TRUE,
      'colour' => [
        '#type' => 'textfield',
        '#title' => $this->t('Highlight Colour'),
        '#default_value' => $default_values['colour'] ?? '&H00FFFFFF',
      ],
      'scale' => [
        '#type' => 'number',
        '#title' => $this->t('Highlight Scale'),
        '#default_value' => $default_values['scale'] ?? 100,
        '#min' => 50,
        '#max' => 300,
      ],
      'position' => [
        '#type' => 'number',
        '#title' => $this->t('Highlight Position'),
        '#default_value' => $default_values['position'] ?? 0,
      ],
    ];
  }

  /**
   * Dynamically builds highlight fieldsets.
   */
  protected function buildHighlightFieldsets(array $default_values): array {
    return [
      'primaryHighlight' => $this->buildHighlightFields('Primary Highlight', $default_values['primaryHighlight'] ?? []),
      'secondaryHighlight' => $this->buildHighlightFields('Secondary Highlight', $default_values['secondaryHighlight'] ?? []),
    ];
  }

  /**
   * Saves common fields to the entity or media.
   */
  protected function saveFields(FormStateInterface $form_state, $entity): void {
    $entity->set('fontName', $form_state->getValue('fontName'));
    $entity->set('fontSize', $form_state->getValue('fontSize'));
    $entity->set('primaryColour', $form_state->getValue('primaryColour'));
    $entity->set('secondaryColour', $form_state->getValue('secondaryColour'));
    $entity->set('outlineColour', $form_state->getValue('outlineColour'));
    $entity->set('bold', $form_state->getValue('bold'));
    $entity->set('italic', $form_state->getValue('italic'));
    $entity->set('underline', $form_state->getValue('underline'));
    $entity->set('scaleX', $form_state->getValue('scaleX'));
    $entity->set('scaleY', $form_state->getValue('scaleY'));
    $entity->set('alignment', $form_state->getValue('alignment'));

    if ($form_state->hasValue('primaryHighlight')) {
      $entity->set('primaryHighlight', $form_state->getValue('primaryHighlight'));
    }
    if ($form_state->hasValue('secondaryHighlight')) {
      $entity->set('secondaryHighlight', $form_state->getValue('secondaryHighlight'));
    }
  }
}

