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
      'strikeout' => [
        '#type' => 'checkbox',
        '#title' => $this->t('strikeout'),
        '#default_value' => $default_values['strikeout'] ?? 0,
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
      'spacing' => [
        '#type' => 'number',
        '#title' => $this->t('Spacing'),
        '#default_value' => $default_values['Spacing'] ?? -20,
      ],
      'angle' => [
        '#type' => 'number',
        '#title' => $this->t('Angle'),
        '#default_value' => $default_values['Angle'] ?? 0,
      ],
      'borderStyle' => [
        '#type' => 'number',
        '#title' => $this->t('Border Style'),
        '#default_value' => $default_values['BorderStyle'] ?? 1,
      ],
      'outline' => [
        '#type' => 'number',
        '#title' => $this->t('Outline'),
        '#default_value' => $default_values['Outline'] ?? 3,
      ],
      'shadow' => [
        '#type' => 'number',
        '#title' => $this->t('Shadow'),
        '#default_value' => $default_values['Shadow'] ?? 0,
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
      'marginl' => [
        '#type' => 'number',
        '#title' => $this->t('MarginL'),
        '#default_value' => $default_values['MarginL'] ?? 200,
      ],
      'marginr' => [
        '#type' => 'number',
        '#title' => $this->t('MarginR'),
        '#default_value' => $default_values['MarginR'] ?? 200,
      ],
      'marginv' => [
        '#type' => 'number',
        '#title' => $this->t('MarginV'),
        '#default_value' => $default_values['MarginV'] ?? 200,
      ],
      'encoding' => [
        '#type' => 'number',
        '#title' => $this->t('MarginV'),
        '#default_value' => $default_values['MarginV'] ?? 1,
      ],
      'type' => [
        '#type' => 'select',
        '#title' => $this->t('Style Type'),
        '#options' => [
          'sequence' => $this->t('Sequence'),
          'karaoke' => $this->t('Karaoke'),
          'plain' => $this->t('Plain'),
        ],
        '#default_value' => $default_values['type'] ?? 'plain',
        '#required' => TRUE,
        '#ajax' => [
          'callback' => '::updateDynamicFields',
          'wrapper' => 'dynamic-fields-wrapper',
        ],
      ],
    ];

  }

  /**
   * Builds highlight fieldsets for Primary and Secondary highlights.
   */
  protected function buildHighlightFieldsets(array $default_values): array {
    return [
      'primaryHighlight' => $this->buildHighlightFields('Primary Highlight', $default_values['primaryHighlight'] ?? []),
      'secondaryHighlight' => $this->buildHighlightFields('Secondary Highlight', $default_values['secondaryHighlight'] ?? []),
    ];
  }

  /**
   * Builds individual highlight fields for a given title and default values.
   */
  protected function buildHighlightFields(string $title, array $default_values = []): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t($title),
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="type"]' => ['value' => 'sequence'],
        ],
      ],
      'colour' => [
        '#type' => 'textfield',
        '#title' => $this->t('Highlight Colour'),
        '#default_value' => $default_values['colour'] ?? '&H00FFFFFF',
      ],
      'outline_colour' => [
        '#type' => 'textfield',
        '#title' => $this->t('Outline Colour'),
        '#default_value' => $default_values['outline_colour'] ?? '&H00FFFFFF',
      ],
      'shadow' => [
        '#type' => 'number',
        '#title' => $this->t('Shadow'),
        '#default_value' => $default_values['Shadow'] ?? 0,
      ],
    ];
  }

  /**
   * Dynamically builds fields based on the selected type.
   */
  protected function buildDynamicFields(array $default_values, string $type): array {
    $dynamic_fields = [];

    if ($type === 'sequence') {
      $dynamic_fields += $this->buildHighlightFieldsets($default_values);
    }

    return $dynamic_fields;
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
    $entity->set('type', $form_state->getValue('type'));

    if ($form_state->hasValue('primaryHighlight')) {
      $entity->set('primaryHighlight', $form_state->getValue('primaryHighlight'));
    }
    if ($form_state->hasValue('secondaryHighlight')) {
      $entity->set('secondaryHighlight', $form_state->getValue('secondaryHighlight'));
    }
  }
}

