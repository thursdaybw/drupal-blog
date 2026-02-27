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
    'processing' => 'Processing',
    'ready_for_review' => 'Ready for review',
    'ready_to_shelve' => 'Ready to shelve',
    'shelved' => 'Shelved',
    'published' => 'Published',
    'failed' => 'Failed',
  ];

  private const CONDITION_GRADE_ALLOWED_VALUES = [
    'acceptable' => 'Acceptable',
    'good' => 'Good',
    'very_good' => 'Very good',
    'like_new' => 'Like new',
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

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel('Description')
      ->setSettings(['default_value' => []]);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel('Price')
      ->setRequired(TRUE)
      ->setDefaultValue('29.95')
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);

    $fields['storage_location'] = BaseFieldDefinition::create('string')
      ->setLabel('Storage location')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

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

    $fields['published_sku'] = BaseFieldDefinition::create('string')
      ->setLabel('Published SKU')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

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
