<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Stores SKU records owned by an AI book listing.
 *
 * @ContentEntityType(
 *   id = "ai_listing_inventory_sku",
 *   label = @Translation("AI Listing Inventory SKU"),
 *   base_table = "ai_listing_inventory_sku",
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "sku"
 *   }
 * )
 */
final class AiListingInventorySku extends ContentEntityBase {

  use EntityChangedTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['listing'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('BB AI Listing')
      ->setRequired(TRUE)
      ->setSetting('target_type', 'bb_ai_listing');

    $fields['sku'] = BaseFieldDefinition::create('string')
      ->setLabel('SKU')
      ->setRequired(TRUE)
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Status')
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSetting('allowed_values', [
        'active' => 'Active',
        'retired' => 'Retired',
      ]);

    $fields['is_primary'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Primary SKU')
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }

}
