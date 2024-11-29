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
 *   },
 * )
 */
final class CaptionStyle extends ConfigEntityBase implements CaptionStyleInterface {

  /**
   * The unique ID of the caption style.
   */
  protected string $id;

  /**
   * The label of the caption style.
   */
  protected string $label;

  /**
   * A brief description of the caption style.
   */
  protected ?string $description = NULL;

  /**
   * The type of the style (sequence, karaoke, plain).
   */
  protected string $type;

  /**
   * The default font name for the style.
   */
  protected string $fontName = 'Arial';

  /**
   * The default font size for the style.
   */
  protected int $fontSize = 70;

  /**
   * The primary color for the style.
   */
  protected string $primaryColour = '&H00FFFFFF';

  /**
   * The secondary color for karaoke or highlight effects.
   */
  protected ?string $secondaryColour = NULL;

  /**
   * The outline color for the style.
   */
  protected string $outlineColour = '&H00000000';

  /**
   * The background color for the style.
   */
  protected string $backColour = '&H00000000';

  /**
   * The bold property for the style (-1 for true, 0 for false).
   */
  protected int $bold = -1;

  /**
   * The italic property for the style (0 for false, 1 for true).
   */
  protected int $italic = 0;

  /**
   * The underline property for the style (0 for false, 1 for true).
   */
  protected int $underline = 0;

  /**
   * The scale factors for the X and Y axes.
   */
  protected int $scaleX = 100;
  protected int $scaleY = 100;

  /**
   * The spacing between characters.
   */
  protected int $spacing = 0;

  /**
   * The alignment for the style.
   */
  protected int $alignment = 2;

  /**
   * The margins for left, right, and vertical alignment.
   */
  protected int $marginL = 200;
  protected int $marginR = 200;
  protected int $marginV = 300;

  /**
   * The encoding value for the style.
   */
  protected int $encoding = 1;

  /**
   * Highlight settings for primary words.
   */
  protected array $primaryHighlight = [];

  /**
   * Highlight settings for secondary words.
   */
  protected array $secondaryHighlight = [];

}

