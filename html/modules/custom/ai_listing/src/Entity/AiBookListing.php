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
    'ready_for_review' => 'Ready for review',
    'ready_to_shelve' => 'Ready to shelve',
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

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel('Description')
      ->setDescription('Item description for eBay listings.')
      ->setSettings([
        'default_value' => [],
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea_with_summary',
        'weight' => 0,
        'settings' => [
          'rows' => 12,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel('Suggested eBay price')
      ->setDescription('Suggested listing price for the marketplace.')
      ->setRequired(true)
      ->setDefaultValue('29.95')
      ->setSetting('precision', 10)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['storage_location'] = BaseFieldDefinition::create('string')
      ->setLabel('Storage location')
      ->setDescription('Location or shelf code to inject into the SKU when publishing.')
      ->setDefaultValue('')
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['bargain_bin'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Bargain bin preset')
      ->setDescription('Flag that indicates this listing should use the bargain bin shipping policy.')
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['condition_issues'] = BaseFieldDefinition::create('string')
      ->setLabel('Condition issues')
      ->setDescription('Normalized list of condition issues.')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDefaultValue([]);

    $fields['condition_grade'] = BaseFieldDefinition::create('list_string')
      ->setLabel('Condition grade')
      ->setDescription('Overall condition grade.')
      ->setRequired(TRUE)
      ->setDefaultValue('like_new')
      ->setSetting('allowed_values', [
        'acceptable' => 'Acceptable',
        'good' => 'Good',
        'very_good' => 'Very good',
        'like_new' => 'Like new',
      ]);

    $fields['condition_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Condition note')
      ->setDescription('Full condition sentence used in listing.');

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


    $fieldConfig = [
      'status' => ['type' => 'list', 'allowed' => self::statusAllowedValues()],
      'title' => ['type' => 'string'],
      'subtitle' => ['type' => 'string'],
      'full_title' => ['type' => 'string'],
      'author' => ['type' => 'string'],
      'isbn' => ['type' => 'string', 'size' => 32],
      'publisher' => ['type' => 'string'],
      'publication_year' => ['type' => 'string', 'size' => 12],
      'format' => ['type' => 'string'],
      'language' => ['type' => 'string'],
      'genre' => ['type' => 'string'],
      'narrative_type' => ['type' => 'string'],
      'country_printed' => ['type' => 'string'],
      'edition' => ['type' => 'string'],
      'series' => ['type' => 'string'],
      'features' => ['type' => 'string'],
      'ebay_title' => ['type' => 'string'],
      'description' => ['type' => 'textarea', 'rows' => 5],
      'condition_grade' => ['type' => 'list'],
      'condition_issues' => ['type' => 'string'],
      'condition_note' => ['type' => 'textarea', 'rows' => 3],
      'images' => ['type' => 'entity_reference'],
      'metadata_json' => ['type' => 'textarea', 'rows' => 5, 'region' => 'hidden'],
      'condition_json' => ['type' => 'textarea', 'rows' => 5, 'region' => 'hidden'],
    ];

    $weight = 0;

    foreach ($fieldConfig as $fieldKey => $config) {

      switch ($config['type']) {

      case 'string':
        $size = $config['size'] ?? 60;
        $fields[$fieldKey] = self::configureStringField(
          $fields[$fieldKey],
          $weight,
          $size
        );
        break;

      case 'textarea':
        $rows = $config['rows'] ?? 5;
        $region = $config['region'] ?? 'content';
        $fields[$fieldKey] = self::configureTextAreaField(
          $fields[$fieldKey],
          $weight,
          $rows,
          $region
        );
        break;

      case 'entity_reference':
        $fields[$fieldKey] = self::configureEntityReferenceField(
          $fields[$fieldKey],
          $weight
        );
        break;

      case 'list':
        $allowed = $config['allowed'] ?? $fields[$fieldKey]->getSetting('allowed_values');
        $fields[$fieldKey] = self::configureListField(
          $fields[$fieldKey],
          $weight,
          $allowed
        );
        break;
      }

      $weight++;
    }
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
