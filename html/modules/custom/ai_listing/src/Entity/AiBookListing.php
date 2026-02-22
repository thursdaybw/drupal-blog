<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Defines the AI Book Listing entity.
 *
 * @ContentEntityType(
 *   id = "ai_book_listing",
 *   label = @Translation("AI Book Listing"),
 *   base_table = "ai_book_listing",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *   }
 * )
 */
final class AiBookListing extends ContentEntityBase {

  use EntityChangedTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel('Status')
      ->setRequired(true)
      ->setDefaultValue('new');

    $fields['images'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Images')
      ->setSetting('target_type', 'file')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['metadata_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Metadata JSON');

    $fields['condition_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Condition JSON');

    $fields['ebay_item_id'] = BaseFieldDefinition::create('string')
      ->setLabel('eBay Item ID');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }
}
