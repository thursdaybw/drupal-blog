<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedTrait;

/**
 * Defines the Ebay Account entity.
 *
 * @ContentEntityType(
 *   id = "ebay_account",
 *   label = @Translation("Ebay Account"),
 *   base_table = "ebay_account",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "uid" = "uid"
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *   },
 *   admin_permission = "administer ebay connector"
 * )
 */
final class EbayAccount extends ContentEntityBase {

  use EntityChangedTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel('Account Label')
      ->setRequired(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Owner')
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['environment'] = BaseFieldDefinition::create('string')
      ->setLabel('Environment')
      ->setRequired(TRUE)
      ->setDefaultValue('production');

    $fields['access_token'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Access Token')
      ->setRequired(TRUE);

    $fields['refresh_token'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Refresh Token')
      ->setRequired(TRUE);

    $fields['expires_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel('Expires At')
      ->setRequired(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed');

    return $fields;
  }

}
