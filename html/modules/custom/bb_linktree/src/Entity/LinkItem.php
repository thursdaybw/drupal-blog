<?php

declare(strict_types=1);

namespace Drupal\bb_linktree\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the link item entity.
 *
 * @ContentEntityType(
 *   id = "bb_linktree_item",
 *   label = @Translation("Link item"),
 *   label_collection = @Translation("Link items"),
 *   label_singular = @Translation("link item"),
 *   label_plural = @Translation("link items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count link item",
 *     plural = "@count link items",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\bb_linktree\LinkItemListBuilder",
 *     "form" = {
 *       "add" = "Drupal\bb_linktree\Form\LinkItemForm",
 *       "edit" = "Drupal\bb_linktree\Form\LinkItemForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "bb_linktree_item",
 *   admin_permission = "administer bb linktree",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "collection" = "/admin/applications/linktree/items",
 *     "add-form" = "/admin/applications/linktree/items/add",
 *     "canonical" = "/admin/applications/linktree/items/{bb_linktree_item}",
 *     "edit-form" = "/admin/applications/linktree/items/{bb_linktree_item}/edit",
 *     "delete-form" = "/admin/applications/linktree/items/{bb_linktree_item}/delete"
 *   }
 * )
 */
final class LinkItem extends ContentEntityBase {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (!$this->getOwnerId()) {
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['link_page'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Link page'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'bb_linktree_page')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -19,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -18,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['destination'] = BaseFieldDefinition::create('link')
      ->setLabel(t('Destination'))
      ->setRequired(TRUE)
      ->setSettings([
        'link_type' => 17,
        'title' => DRUPAL_DISABLED,
      ])
      ->setDisplayOptions('form', [
        'type' => 'link_default',
        'weight' => -17,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => -16,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Published')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => -15,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
