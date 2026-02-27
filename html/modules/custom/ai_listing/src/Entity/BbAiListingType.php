<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Bundle type for BB AI listings.
 *
 * @ConfigEntityType(
 *   id = "bb_ai_listing_type",
 *   label = @Translation("BB AI Listing type"),
 *   label_collection = @Translation("BB AI Listing types"),
 *   handlers = {
 *     "list_builder" = "Drupal\Core\Config\Entity\ConfigEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_listing\Form\BbAiListingTypeForm",
 *       "edit" = "Drupal\ai_listing\Form\BbAiListingTypeForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   admin_permission = "administer ai listings",
 *   config_prefix = "bb_ai_listing_type",
 *   bundle_of = "bb_ai_listing",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/bb-ai-listing-types/add",
 *     "edit-form" = "/admin/structure/bb-ai-listing-types/manage/{bb_ai_listing_type}",
 *     "delete-form" = "/admin/structure/bb-ai-listing-types/manage/{bb_ai_listing_type}/delete",
 *     "collection" = "/admin/structure/bb-ai-listing-types"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description"
 *   }
 * )
 */
final class BbAiListingType extends ConfigEntityBundleBase {

  /**
   * The machine name for this listing type.
   *
   * @var string
   */
  protected $id;

  /**
   * The human readable label.
   *
   * @var string
   */
  protected $label;

  /**
   * Description shown in admin listings.
   *
   * @var string
   */
  protected $description = '';

}

