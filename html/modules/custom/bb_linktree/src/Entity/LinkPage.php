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
 * Defines the link page entity.
 *
 * @ContentEntityType(
 *   id = "bb_linktree_page",
 *   label = @Translation("Link page"),
 *   label_collection = @Translation("Link pages"),
 *   label_singular = @Translation("link page"),
 *   label_plural = @Translation("link pages"),
 *   label_count = @PluralTranslation(
 *     singular = "@count link page",
 *     plural = "@count link pages",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\bb_linktree\LinkPageListBuilder",
 *     "form" = {
 *       "add" = "Drupal\bb_linktree\Form\LinkPageForm",
 *       "edit" = "Drupal\bb_linktree\Form\LinkPageForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "bb_linktree_page",
 *   admin_permission = "administer bb linktree",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "title",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "collection" = "/admin/applications/linktree/pages",
 *     "add-form" = "/admin/applications/linktree/pages/add",
 *     "canonical" = "/admin/applications/linktree/pages/{bb_linktree_page}",
 *     "edit-form" = "/admin/applications/linktree/pages/{bb_linktree_page}/edit",
 *     "delete-form" = "/admin/applications/linktree/pages/{bb_linktree_page}/delete"
 *   }
 * )
 */
final class LinkPage extends ContentEntityBase {

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
   * Gets the public path segment.
   */
  public function getPathSegment(): string {
    return (string) $this->get('path_segment')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['path_segment'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Path segment'))
      ->setDescription(t('Used in the public route /linktree/{path_segment}.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -19,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['intro'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Intro'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Published')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['is_default'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Default page'))
      ->setDescription(t('The default page is shown when /linktree is visited without a page path.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 1,
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
