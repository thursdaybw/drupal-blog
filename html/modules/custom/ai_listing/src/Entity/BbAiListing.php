<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Base listing entity for Bevan's Bench listing workflows.
 *
 * @ContentEntityType(
 *   id = "bb_ai_listing",
 *   label = @Translation("BB AI Listing"),
 *   bundle_label = @Translation("Listing type"),
 *   base_table = "bb_ai_listing",
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "ebay_title",
 *     "bundle" = "listing_type"
 *   },
 *   bundle_entity_type = "bb_ai_listing_type",
 *   field_ui_base_route = "entity.bb_ai_listing_type.edit_form"
 * )
 */
final class BbAiListing extends ContentEntityBase {

  use EntityChangedTrait;

  private const STATUS_ALLOWED_VALUES = [
    'new' => 'New',
    'ready_for_inference' => 'Ready for inference',
    'processing' => 'Processing',
    'ready_for_review' => 'Ready for review',
    'ready_to_shelve' => 'Ready to shelve',
    'ready_to_publish' => 'Ready to publish',
    'shelved' => 'Shelved',
    'archived' => 'Archived',
    'lost' => 'Lost',
    'failed' => 'Failed',
  ];

  private const CONDITION_GRADE_ALLOWED_VALUES = [
    'acceptable' => 'Acceptable',
    'good' => 'Good',
    'very_good' => 'Very good',
    'like_new' => 'Like new',
  ];

  private const KEEP_SCORE_ALLOWED_VALUES = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
  ];

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Status')
      ->setRequired(TRUE)
      ->setDefaultValue('new')
      ->setSetting('allowed_values', self::STATUS_ALLOWED_VALUES);

    $fields['ebay_title'] = BaseFieldDefinition::create('string')
      ->setLabel('eBay title')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['listing_code'] = BaseFieldDefinition::create('string')
      ->setLabel('Listing code')
      ->setDescription('Stable short code used in new marketplace SKUs.')
      ->setSetting('max_length', 32);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel('Description')
      ->setSettings(['default_value' => []]);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel('Price')
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);

    $fields['storage_location'] = BaseFieldDefinition::create('string')
      ->setLabel('Storage location')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['storage_location_term'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Storage location term')
      ->setDescription('Registered storage location term for this listing.')
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'storage_location' => 'storage_location',
        ],
        'auto_create' => FALSE,
      ])
      ->setRequired(FALSE);

    $fields['keep_score'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Keep score')
      ->setDescription('Operator judgement for how worth keeping this item is in inventory.')
      ->setRequired(FALSE)
      ->setSetting('allowed_values', self::KEEP_SCORE_ALLOWED_VALUES);

    $fields['condition_grade'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Condition grade')
      ->setRequired(TRUE)
      ->setDefaultValue('good')
      ->setSetting('allowed_values', self::CONDITION_GRADE_ALLOWED_VALUES);

    $fields['condition_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Condition note');

    $fields['bargain_bin'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Bargain bin preset')
      ->setRequired(TRUE)
      ->setDefaultValue(FALSE);

    $fields['metadata_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Metadata JSON');

    $fields['condition_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Condition JSON');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }

}
