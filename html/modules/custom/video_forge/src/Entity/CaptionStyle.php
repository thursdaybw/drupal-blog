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
  protected string $fontName = 'Arial';
  protected int $fontSize = 70;
  protected string $primaryColour = '&H00FFFFFF';
  protected ?string $secondaryColour = NULL;
  protected string $outlineColour = '&H00000000';
  protected string $backColour = '&H00000000';
  protected int $bold = -1;
  protected int $italic = 0;
  protected int $underline = 0;
  protected int $scaleX = 100;
  protected int $scaleY = 100;
  protected int $alignment = 2;
  protected int $marginL = 200;
  protected int $marginR = 200;
  protected int $marginV = 300;
  protected array $primaryHighlight = [];
  protected array $secondaryHighlight = [];
}

