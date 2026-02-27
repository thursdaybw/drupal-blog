<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines one inferred book item inside a bundle listing.
 *
 * @ContentEntityType(
 *   id = "ai_book_bundle_item",
 *   label = @Translation("AI Book Bundle Item"),
 *   base_table = "ai_book_bundle_item",
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   }
 * )
 */
final class AiBookBundleItem extends ContentEntityBase {

  use EntityChangedTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['bundle_listing'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Bundle listing')
      ->setRequired(TRUE)
      ->setSetting('target_type', 'bb_ai_listing');

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel('Weight')
      ->setRequired(TRUE)
      ->setDefaultValue(0);

    // Cached per-book inferred fields for bundle UI/aggregation.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel('Title')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['author'] = BaseFieldDefinition::create('string')
      ->setLabel('Author')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['isbn'] = BaseFieldDefinition::create('string')
      ->setLabel('ISBN')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 32]);

    $fields['metadata_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Metadata JSON');

    $fields['condition_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Condition JSON');

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

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Notes');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }

}
