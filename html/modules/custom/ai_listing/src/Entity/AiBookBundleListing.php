<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines a sellable bundle listing (one sale unit containing multiple books).
 *
 * @ContentEntityType(
 *   id = "ai_book_bundle_listing",
 *   label = @Translation("AI Book Bundle Listing"),
 *   base_table = "ai_book_bundle_listing",
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   }
 * )
 */
final class AiBookBundleListing extends ContentEntityBase {

  use EntityChangedTrait;

  private const STATUS_ALLOWED_VALUES = [
    'new' => 'New',
    'processing' => 'Processing',
    'ready_for_review' => 'Ready for review',
    'ready_to_shelve' => 'Ready to shelve',
    'shelved' => 'Shelved',
    'failed' => 'Failed',
  ];

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Status')
      ->setRequired(TRUE)
      ->setDefaultValue('new')
      ->setSetting('allowed_values', self::STATUS_ALLOWED_VALUES);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel('Title')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['ebay_title'] = BaseFieldDefinition::create('string')
      ->setLabel('eBay title')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel('Description')
      ->setSettings(['default_value' => []]);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel('Suggested price')
      ->setRequired(TRUE)
      ->setDefaultValue('29.95')
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);

    $fields['storage_location'] = BaseFieldDefinition::create('string')
      ->setLabel('Storage location')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['bargain_bin'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Bargain bin preset')
      ->setRequired(TRUE)
      ->setDefaultValue(FALSE);

    $fields['condition_issues'] = BaseFieldDefinition::create('string')
      ->setLabel('Condition issues')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDefaultValue([]);

    $fields['condition_grade'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Condition grade')
      ->setRequired(TRUE)
      ->setDefaultValue('good')
      ->setSetting('allowed_values', [
        'acceptable' => 'Acceptable',
        'good' => 'Good',
        'very_good' => 'Very good',
        'like_new' => 'Like new',
      ]);

    $fields['condition_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Condition note');

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
