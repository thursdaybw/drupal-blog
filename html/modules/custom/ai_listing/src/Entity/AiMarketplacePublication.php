<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Stores marketplace publication records for listings.
 *
 * @ContentEntityType(
 *   id = "ai_marketplace_publication",
 *   label = @Translation("AI Marketplace Publication"),
 *   base_table = "ai_marketplace_publication",
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "marketplace_publication_id"
 *   }
 * )
 */
final class AiMarketplacePublication extends ContentEntityBase {

  use EntityChangedTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['ai_book_listing'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('AI Book Listing')
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_book_listing');

    $fields['inventory_sku'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Inventory SKU')
      ->setRequired(FALSE)
      ->setSetting('target_type', 'ai_listing_inventory_sku');

    $fields['inventory_sku_value'] = BaseFieldDefinition::create('string')
      ->setLabel('Inventory SKU Value')
      ->setRequired(FALSE)
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['marketplace_key'] = BaseFieldDefinition::create('string')
      ->setLabel('Marketplace Key')
      ->setRequired(TRUE)
      ->setDefaultValue('')
      ->setSettings(['max_length' => 64]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Status')
      ->setRequired(TRUE)
      ->setDefaultValue('draft')
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'publishing' => 'Publishing',
        'published' => 'Published',
        'failed' => 'Failed',
        'ended' => 'Ended',
      ]);

    $fields['publication_type'] = BaseFieldDefinition::create('string')
      ->setLabel('Publication Type')
      ->setRequired(FALSE)
      ->setDefaultValue('')
      ->setSettings(['max_length' => 64]);

    $fields['marketplace_publication_id'] = BaseFieldDefinition::create('string')
      ->setLabel('Marketplace Publication ID')
      ->setRequired(FALSE)
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['marketplace_listing_id'] = BaseFieldDefinition::create('string')
      ->setLabel('Marketplace Listing ID')
      ->setRequired(FALSE)
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['last_error_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Last Error Message');

    $fields['published_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel('Published At')
      ->setRequired(FALSE);

    $fields['ended_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel('Ended At')
      ->setRequired(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }

}
