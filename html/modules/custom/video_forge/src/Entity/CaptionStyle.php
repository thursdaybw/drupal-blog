<?php

declare(strict_types=1);

namespace Drupal\video_forge\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\video_forge\CaptionStyleInterface;

/**
 * Defines the caption style entity type.
 *
 * @ConfigEntityType(
 *   id = "caption_style",
 *   label = @Translation("Caption Style"),
 *   label_collection = @Translation("Caption Styles"),
 *   label_singular = @Translation("caption style"),
 *   label_plural = @Translation("caption styles"),
 *   label_count = @PluralTranslation(
 *     singular = "@count caption style",
 *     plural = "@count caption styles",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\video_forge\CaptionStyleListBuilder",
 *     "form" = {
 *       "add" = "Drupal\video_forge\Form\CaptionStyleForm",
 *       "edit" = "Drupal\video_forge\Form\CaptionStyleForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "caption_style",
 *   admin_permission = "administer caption_style",
 *   links = {
 *     "collection" = "/admin/structure/caption-style",
 *     "add-form" = "/admin/structure/caption-style/add",
 *     "edit-form" = "/admin/structure/caption-style/{caption_style}",
 *     "delete-form" = "/admin/structure/caption-style/{caption_style}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "type",
 *     "fontName",
 *     "fontSize",
 *     "primaryColour",
 *     "secondaryColour",
 *     "outlineColour",
 *     "backColour",
 *     "bold",
 *     "italic",
 *     "underline",
 *     "scaleX",
 *     "scaleY",
 *     "alignment",
 *     "marginL",
 *     "marginR",
 *     "marginV",
 *     "primaryHighlight",
 *     "secondaryHighlight"
 *   },
 * )
 */
final class CaptionStyle extends ConfigEntityBase implements CaptionStyleInterface {

  protected string $id;
  protected string $label;
  protected ?string $description = NULL;
  protected string $type;

  /**
   * Get metadata for all fields in the CaptionStyle entity.
   *
   * @return array
   *   An associative array where keys are field machine names and values are metadata.
   */
public static function getFieldDefinitions(): array {
  return [
    'fontName' => [
      'label' => 'Font Name',
      'type' => 'string',
      'default' => 'Arial',
      'ass_key' => 'Fontname',
    ],
    'fontSize' => [
      'label' => 'Font Size',
      'type' => 'integer',
      'default' => 70,
      'ass_key' => 'Fontsize',
    ],
    'primaryColour' => [
      'label' => 'Primary Colour',
      'type' => 'string',
      'default' => '&H00FFFFFF',
      'ass_key' => 'PrimaryColour',
    ],
    'secondaryColour' => [
      'label' => 'Secondary Colour',
      'type' => 'string',
      'default' => '&H00000000',
      'ass_key' => 'SecondaryColour',
    ],
    'outlineColour' => [
      'label' => 'Outline Colour',
      'type' => 'string',
      'default' => '&H00000000',
      'ass_key' => 'OutlineColour',
    ],
    'backColour' => [
      'label' => 'Background Colour',
      'type' => 'string',
      'default' => '&H00000000',
      'ass_key' => 'BackColour',
    ],
    'bold' => [
      'label' => 'Bold',
      'type' => 'integer',
      'default' => -1,
      'ass_key' => 'Bold',
    ],
    'italic' => [
      'label' => 'Italic',
      'type' => 'integer',
      'default' => 0,
      'ass_key' => 'Italic',
    ],
    'underline' => [
      'label' => 'Underline',
      'type' => 'integer',
      'default' => 0,
      'ass_key' => 'Underline',
    ],
    'strikeOut' => [
      'label' => 'Strike Out',
      'type' => 'integer',
      'default' => 0,
      'ass_key' => 'StrikeOut',
    ],
    'scaleX' => [
      'label' => 'Scale X',
      'type' => 'integer',
      'default' => 100,
      'ass_key' => 'ScaleX',
    ],
    'scaleY' => [
      'label' => 'Scale Y',
      'type' => 'integer',
      'default' => 100,
      'ass_key' => 'ScaleY',
    ],
    'spacing' => [
      'label' => 'Spacing',
      'type' => 'integer',
      'default' => 0,
      'ass_key' => 'Spacing',
    ],
    'angle' => [
      'label' => 'Angle',
      'type' => 'integer',
      'default' => 0,
      'ass_key' => 'Angle',
    ],
    'borderStyle' => [
      'label' => 'Border Style',
      'type' => 'integer',
      'default' => 1,
      'ass_key' => 'BorderStyle',
    ],
    'outline' => [
      'label' => 'Outline',
      'type' => 'integer',
      'default' => 1,
      'ass_key' => 'Outline',
    ],
    'shadow' => [
      'label' => 'Shadow',
      'type' => 'integer',
      'default' => 0,
      'ass_key' => 'Shadow',
    ],
    'alignment' => [
      'label' => 'Alignment',
      'type' => 'integer',
      'default' => 2,
      'ass_key' => 'Alignment',
    ],
    'marginL' => [
      'label' => 'Margin Left',
      'type' => 'integer',
      'default' => 200,
      'ass_key' => 'MarginL',
    ],
    'marginR' => [
      'label' => 'Margin Right',
      'type' => 'integer',
      'default' => 200,
      'ass_key' => 'MarginR',
    ],
    'marginV' => [
      'label' => 'Margin Vertical',
      'type' => 'integer',
      'default' => 300,
      'ass_key' => 'MarginV',
    ],
    'encoding' => [
      'label' => 'Encoding',
      'type' => 'integer',
      'default' => 1,
      'ass_key' => 'Encoding',
    ],
  ];
}

  protected string $fontName = 'Arial';
  protected int $fontSize = 70;
  protected string $primaryColour = '&H00FFFFFF';
  protected ?string $secondaryColour = NULL;
  protected string $outlineColour = '&H00000000';
  protected string $backColour = '&H00000000';
  protected int $bold = -1;
  protected int $italic = 0;
  protected int $underline = 0;
  protected int $strikeout = 0;
  protected int $scaleX = 100;
  protected int $scaleY = 100;
  protected int $alignment = 2;
  protected int $spaceing = -20;
  protected int $marginL = 200;
  protected int $marginR = 200;
  protected int $marginV = 300;
  protected array $primaryHighlight = [];
  protected array $secondaryHighlight = [];

}

