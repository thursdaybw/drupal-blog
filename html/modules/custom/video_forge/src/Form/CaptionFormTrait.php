<?php

declare(strict_types=1);

namespace Drupal\video_forge\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\video_forge\Entity\CaptionStyle;

/**
 * Provides shared logic for caption forms.
 */
trait CaptionFormTrait {

  /**
   * Builds common fields for caption styles.
   */
	protected function buildCommonFields(array $default_values = []): array {
		$fields = CaptionStyle::getFieldDefinitions();
		$form = [];
		foreach ($fields as $field_name => $metadata) {
			if (isset($metadata['ass_key'])) {
				$form[$field_name] = [
					'#type' => $metadata['type'] === 'integer' ? 'number' : 'textfield',
					'#title' => $this->t($metadata['label']),
					'#default_value' => $default_values[$field_name] ?? $metadata['default'],
				];
			}
		}
		return $form;
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

    $common_fields = $this->buildCommonFields($default_values);

    return array_merge([
      '#type' => 'fieldset',
      '#title' => $this->t($title),
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="type"]' => ['value' => 'sequence'],
        ],
      ],
    ], $common_fields);
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

	  $fields = CaptionStyle::getFieldDefinitions();
	  foreach ($fields as $field_name => $metadata) {
		  if ($form_state->hasValue($field_name)) {
			  $entity->set($field_name, $form_state->getValue($field_name));
		  }
	  }

    // print_r($form_state->getValue('type')); exit;
    if ($form_state->hasValue('type')) {
      $entity->set('type', $form_state->getValue('type'));
    }

    if ($form_state->hasValue('primaryHighlight')) {
      $entity->set('primaryHighlight', $form_state->getValue('primaryHighlight'));
    }
    if ($form_state->hasValue('secondaryHighlight')) {
      $entity->set('secondaryHighlight', $form_state->getValue('secondaryHighlight'));
    }
  }
}

