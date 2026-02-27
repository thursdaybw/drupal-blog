<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Stores one image owned by a listing inference unit.
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

    $fields['owner'] = BaseFieldDefinition::create('dynamic_entity_reference')
      ->setLabel('Owner')
      ->setRequired(TRUE)
      ->setSetting('exclude_entity_types', FALSE)
      ->setSetting('entity_type_ids', [
        'bb_ai_listing' => 'bb_ai_listing',
        'ai_book_listing' => 'ai_book_listing',
        'ai_book_bundle_listing' => 'ai_book_bundle_listing',
        'ai_book_bundle_item' => 'ai_book_bundle_item',
      ]);

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

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $ownerItem = $this->get('owner')->first();
    $ownerTargetType = (string) ($ownerItem?->target_type ?? '');
    $ownerTargetId = (int) ($ownerItem?->target_id ?? 0);

    if ($ownerTargetType === '' || $ownerTargetId === 0) {
      throw new \InvalidArgumentException('ListingImage owner is required.');
    }

    if ($ownerTargetType === 'bb_ai_listing') {
      return;
    }

    if ($ownerTargetType === 'ai_book_listing') {
      return;
    }

    if ($ownerTargetType === 'ai_book_bundle_listing') {
      return;
    }

    if ($ownerTargetType === 'ai_book_bundle_item') {
      return;
    }

    throw new \InvalidArgumentException('ListingImage owner type is not supported: ' . $ownerTargetType);
  }

}
