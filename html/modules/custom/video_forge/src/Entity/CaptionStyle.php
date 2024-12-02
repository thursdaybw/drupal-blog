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
 *     "strikeOut",
 *     "scaleX",
 *     "scaleY",
 *     "spacing",
 *     "angle",
 *     "borderStyle",
 *     "outline",
 *     "shadow",
 *     "alignment",
 *     "marginL",
 *     "marginR",
 *     "marginV",
 *     "encoding",
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
            'description' => 'Specifies the font used for rendering subtitles. Common examples include Arial, Times New Roman, or Impact.',
        ],
        'fontSize' => [
            'label' => 'Font Size',
            'type' => 'integer',
            'default' => 70,
            'ass_key' => 'Fontsize',
            'description' => 'Defines the size of the font in points. Larger values result in larger text. Typical range is 10 to 100.',
        ],
        'primaryColour' => [
            'label' => 'Primary Colour',
            'type' => 'string',
            'default' => '&H00FFFFFF',
            'ass_key' => 'PrimaryColour',
            'description' => 'Color values are expressed in hexadecimal BGR format as &HBBGGRR& or ABGR (with alpha channel) as &HAABBGGRR&. Transparency (alpha) can be expressed as &HAA&. Note that in the alpha channel, 00 is opaque and FF is transparent.',
        ],
        'secondaryColour' => [
            'label' => 'Secondary Colour',
            'type' => 'string',
            'default' => '&H00000000',
            'ass_key' => 'SecondaryColour',
            'description' => 'A secondary text color, typically used for karaoke effects or to complement the primary color.',
        ],
        'outlineColour' => [
            'label' => 'Outline Colour',
            'type' => 'string',
            'default' => '&H00000000',
            'ass_key' => 'OutlineColour',
            'description' => 'The color of the text outline, formatted as &H00RRGGBB. Use this to add contrast around the text.',
        ],
        'backColour' => [
            'label' => 'Background Colour',
            'type' => 'string',
            'default' => '&H00000000',
            'ass_key' => 'BackColour',
            'description' => 'Defines the background color behind the text. Use &H00000000 for transparency.',
        ],
        'bold' => [
            'label' => 'Bold',
            'type' => 'integer',
            'default' => -1,
            'ass_key' => 'Bold',
            'description' => 'Set to 1 to enable bold text. Set to 0 for normal weight text.',
        ],
        'italic' => [
            'label' => 'Italic',
            'type' => 'integer',
            'default' => 0,
            'ass_key' => 'Italic',
            'description' => 'Set to 1 to enable italic text. Set to 0 for regular text.',
        ],
        'underline' => [
            'label' => 'Underline',
            'type' => 'integer',
            'default' => 0,
            'ass_key' => 'Underline',
            'description' => 'Set to 1 to underline the text. Set to 0 for no underline.',
        ],
        'strikeOut' => [
            'label' => 'Strike Out',
            'type' => 'integer',
            'default' => 0,
            'ass_key' => 'StrikeOut',
            'description' => 'Set to 1 to strike through the text. Set to 0 for no strikethrough.',
        ],
        'scaleX' => [
            'label' => 'Scale X',
            'type' => 'integer',
            'default' => 100,
            'ass_key' => 'ScaleX',
            'description' => 'Adjusts the horizontal scaling of the text in percentage. For example, 150 will stretch the text to 1.5x width.',
        ],
        'scaleY' => [
            'label' => 'Scale Y',
            'type' => 'integer',
            'default' => 100,
            'ass_key' => 'ScaleY',
            'description' => 'Adjusts the vertical scaling of the text in percentage. For example, 150 will stretch the text to 1.5x height.',
        ],
        'spacing' => [
            'label' => 'Spacing',
            'type' => 'integer',
            'default' => 0,
            'ass_key' => 'Spacing',
            'description' => 'Defines the amount of extra spacing (in pixels) between characters. Use positive values for extra spacing.',
        ],
        'angle' => [
            'label' => 'Angle',
            'type' => 'integer',
            'default' => 0,
            'ass_key' => 'Angle',
            'description' => 'Sets the rotation angle of the text in degrees. 0 means no rotation, while 90 rotates the text vertically.',
        ],
        'borderStyle' => [
            'label' => 'Border Style',
            'type' => 'integer',
            'default' => 1,
            'ass_key' => 'BorderStyle',
            'description' => 'Defines the border style. Set to 1 for an outline, 3 for opaque box.',
        ],
        'outline' => [
            'label' => 'Outline',
            'type' => 'integer',
            'default' => 1,
            'ass_key' => 'Outline',
            'description' => 'Specifies the thickness of the text outline in pixels. For example, 2 will add a 2-pixel thick outline.',
        ],
        'shadow' => [
            'label' => 'Shadow',
            'type' => 'integer',
            'default' => 0,
            'ass_key' => 'Shadow',
            'description' => 'Defines the shadow depth for the text in pixels. For example, 1 will add a subtle shadow.',
        ],
        'alignment' => [
            'label' => 'Alignment',
            'type' => 'integer',
            'default' => 2,
            'ass_key' => 'Alignment',
            'description' => 'Controls text alignment: 1 for left-aligned, 2 for center-aligned, 3 for right-aligned.',
        ],
        'marginL' => [
            'label' => 'Margin Left',
            'type' => 'integer',
            'default' => 200,
            'ass_key' => 'MarginL',
            'description' => 'Specifies the left margin of the text in pixels.',
        ],
        'marginR' => [
            'label' => 'Margin Right',
            'type' => 'integer',
            'default' => 200,
            'ass_key' => 'MarginR',
            'description' => 'Specifies the right margin of the text in pixels.',
        ],
        'marginV' => [
            'label' => 'Margin Vertical',
            'type' => 'integer',
            'default' => 300,
            'ass_key' => 'MarginV',
            'description' => 'Specifies the vertical margin of the text in pixels.',
        ],
        'encoding' => [
            'label' => 'Encoding',
            'type' => 'integer',
            'default' => 1,
            'ass_key' => 'Encoding',
            'description' => 'Specifies the character set encoding. Common values include 1 for Western European languages.',
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
  protected int $spacing = 0;
  protected int $angle = 0;
  protected int $borderStyle = 1;
  protected int $outline = 1;
  protected int $shadow = 0;
  protected int $alignment = 2;
  protected int $marginL = 200;
  protected int $marginR = 200;
  protected int $marginV = 300;
  protected array $primaryHighlight = [];
  protected array $secondaryHighlight = [];
  protected int $encoding = 1;

}

