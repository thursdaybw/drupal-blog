<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Stores images owned by an AI book listing.
 *
 * @ContentEntityType(
 *   id = "listing_image",
 *   label = @Translation("Listing Image"),
 *   base_table = "listing_image",
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "id"
 *   }
 * )
 */
final class ListingImage extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['listing'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Listing')
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_book_listing');

    $fields['file'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('File')
      ->setRequired(TRUE)
      ->setSetting('target_type', 'file');

    $fields['is_metadata_source'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Use for metadata inference')
      ->setRequired(TRUE)
      ->setDefaultValue(FALSE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel('Weight')
      ->setRequired(TRUE)
      ->setDefaultValue(0);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }

}
