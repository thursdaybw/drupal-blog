<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Defines the AI Book Listing entity.
 *
 * @ContentEntityType(
 *   id = "ai_book_listing",
 *   label = @Translation("AI Book Listing"),
 *   base_table = "ai_book_listing",
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "Drupal\ai_listing\ListBuilder\AiBookListingListBuilder",
 *     "form" = {
 *       "default" = "Drupal\ai_listing\Form\AiBookListingForm",
 *       "edit" = "Drupal\ai_listing\Form\AiBookListingForm",
 *       "delete" = "Drupal\ai_listing\Form\AiBookListingDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   admin_permission = "administer ai listings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "full_title"
 *   },
 *   links = {
 *     "canonical" = "/admin/ai-listings/{ai_book_listing}",
 *     "add-form" = "/admin/ai-listing/add",
 *     "edit-form" = "/admin/ai-listings/{ai_book_listing}/edit",
 *     "delete-form" = "/admin/ai-listings/{ai_book_listing}/delete",
 *     "collection" = "/admin/ai-listings"
 *   }
 * )
 */
final class AiBookListing extends ContentEntityBase {

  use EntityChangedTrait;

  private const STATUS_ALLOWED_VALUES = [
    'new' => 'New',
    'processing' => 'Processing',
    'ready' => 'Ready',
    'published' => 'Published',
    'failed' => 'Failed',
  ];

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Status')
      ->setRequired(true)
      ->setDefaultValue('new')
      ->setSetting('allowed_values', self::statusAllowedValues());

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel('Title')
      ->setDescription('Book title extracted from the images.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['subtitle'] = BaseFieldDefinition::create('string')
      ->setLabel('Subtitle')
      ->setDescription('Book subtitle extracted from the images.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['full_title'] = BaseFieldDefinition::create('string')
      ->setLabel('Full title')
      ->setDescription('Full title (title plus subtitle).')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['author'] = BaseFieldDefinition::create('string')
      ->setLabel('Author')
      ->setDescription('Primary author of the book.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['isbn'] = BaseFieldDefinition::create('string')
      ->setLabel('ISBN')
      ->setDescription('Normalized ISBN if available.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 32]);

    $fields['publisher'] = BaseFieldDefinition::create('string')
      ->setLabel('Publisher')
      ->setDescription('Publisher recorded on the book.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['publication_year'] = BaseFieldDefinition::create('string')
      ->setLabel('Publication year')
      ->setDescription('Publication year extracted from the images.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 8]);

    $fields['format'] = BaseFieldDefinition::create('string')
      ->setLabel('Format')
      ->setDescription('Paperback/hardcover when visible.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 64]);

    $fields['language'] = BaseFieldDefinition::create('string')
      ->setLabel('Language')
      ->setDescription('Language of the edition.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 64]);

    $fields['genre'] = BaseFieldDefinition::create('string')
      ->setLabel('Genre')
      ->setDescription('Genre or category inferred.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 128]);

    $fields['narrative_type'] = BaseFieldDefinition::create('string')
      ->setLabel('Narrative type')
      ->setDescription('Fiction, Non-fiction, etc.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 128]);

    $fields['country_printed'] = BaseFieldDefinition::create('string')
      ->setLabel('Country printed')
      ->setDescription('Country where the book was printed.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 128]);

    $fields['edition'] = BaseFieldDefinition::create('string')
      ->setLabel('Edition')
      ->setDescription('Edition information from the book.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 64]);

    $fields['series'] = BaseFieldDefinition::create('string')
      ->setLabel('Series')
      ->setDescription('Series name if applicable.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['features'] = BaseFieldDefinition::create('string')
      ->setLabel('Features')
      ->setDescription('List of special features detected by the extractor.')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDefaultValue([]);

    $fields['ebay_title'] = BaseFieldDefinition::create('string')
      ->setLabel('eBay listing title')
      ->setDescription('Title generated for eBay.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255]);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Description')
      ->setDescription('Item description for eBay listings.');

    $fields['condition_issues'] = BaseFieldDefinition::create('string')
      ->setLabel('Condition issues')
      ->setDescription('Normalized list of condition issues.')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDefaultValue([]);


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

    $fields['status'] = self::configureListField($fields['status'], 0, self::statusAllowedValues());
    $fields['title'] = self::configureStringField($fields['title'], 1);
    $fields['subtitle'] = self::configureStringField($fields['subtitle'], 2);
    $fields['full_title'] = self::configureStringField($fields['full_title'], 3);
    $fields['author'] = self::configureStringField($fields['author'], 4);
    $fields['isbn'] = self::configureStringField($fields['isbn'], 5, 32);
    $fields['publisher'] = self::configureStringField($fields['publisher'], 6);
    $fields['publication_year'] = self::configureStringField($fields['publication_year'], 7, 12);
    $fields['format'] = self::configureStringField($fields['format'], 8);
    $fields['language'] = self::configureStringField($fields['language'], 9);
    $fields['genre'] = self::configureStringField($fields['genre'], 10);
    $fields['narrative_type'] = self::configureStringField($fields['narrative_type'], 11);
    $fields['country_printed'] = self::configureStringField($fields['country_printed'], 12);
    $fields['edition'] = self::configureStringField($fields['edition'], 13);
    $fields['series'] = self::configureStringField($fields['series'], 14);
    $fields['features'] = self::configureStringField($fields['features'], 15);
    $fields['ebay_title'] = self::configureStringField($fields['ebay_title'], 16);
    $fields['description'] = self::configureTextAreaField($fields['description'], 17, 5);
    $fields['condition_issues'] = self::configureStringField($fields['condition_issues'], 18);
    $fields['images'] = self::configureEntityReferenceField($fields['images'], 19);
    $fields['metadata_json'] = self::configureTextAreaField($fields['metadata_json'], 20, 5, 'hidden');
    $fields['condition_json'] = self::configureTextAreaField($fields['condition_json'], 21, 5, 'hidden');

    return $fields;
  }

  private static function configureStringField(BaseFieldDefinition $field, int $weight, int $size = 60, string $region = 'content'): BaseFieldDefinition {
    return self::configureFormField(
      $field,
      'string_textfield',
      $weight,
      [
        'size' => $size,
        'placeholder' => '',
      ],
      $region
    );
  }

  private static function configureTextAreaField(BaseFieldDefinition $field, int $weight, int $rows = 5, string $region = 'content'): BaseFieldDefinition {
    return self::configureFormField(
      $field,
      'text_textarea',
      $weight,
      [
        'rows' => $rows,
        'placeholder' => '',
      ],
      $region
    );
  }

  private static function configureEntityReferenceField(BaseFieldDefinition $field, int $weight, string $region = 'content'): BaseFieldDefinition {
    return self::configureFormField(
      $field,
      'entity_reference_autocomplete',
      $weight,
      [
        'match_operator' => 'CONTAINS',
        'match_limit' => 10,
        'size' => 60,
        'placeholder' => '',
      ],
      $region
    );
  }

  private static function configureFormField(BaseFieldDefinition $field, string $widget, int $weight, array $settings, string $region): BaseFieldDefinition {
    return $field
      ->setDisplayOptions('form', [
        'type' => $widget,
        'weight' => $weight,
        'region' => $region,
        'settings' => $settings,
        'third_party_settings' => [],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  private static function configureListField(BaseFieldDefinition $field, int $weight, array $allowedValues, string $region = 'content'): BaseFieldDefinition {
    return self::configureFormField(
      $field->setSetting('allowed_values', $allowedValues),
      'options_buttons',
      $weight,
      [
        'display_label' => TRUE,
      ],
      $region
    );
  }

  private static function statusAllowedValues(): array {
    return self::STATUS_ALLOWED_VALUES;
  }
}
