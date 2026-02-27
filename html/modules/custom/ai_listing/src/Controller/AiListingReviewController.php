<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Controller;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Form\AiBookBundleListingReviewForm;
use Drupal\ai_listing\Form\AiBookListingReviewForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AiListingReviewController implements ContainerInjectionInterface {

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('form_builder'),
    );
  }

  public function build(BbAiListing $bb_ai_listing): array {
    if ($bb_ai_listing->bundle() === 'book') {
      return $this->formBuilder->getForm(AiBookListingReviewForm::class, $bb_ai_listing);
    }

    if ($bb_ai_listing->bundle() === 'book_bundle') {
      return $this->formBuilder->getForm(AiBookBundleListingReviewForm::class, $bb_ai_listing);
    }

    throw new NotFoundHttpException('Unsupported listing type.');
  }

  public function getTitle(BbAiListing $bb_ai_listing): string {
    $label = trim((string) $bb_ai_listing->label());
    if ($label !== '') {
      return $label;
    }

    return $bb_ai_listing->bundle() === 'book_bundle'
      ? 'Review Book Bundle Listing'
      : 'Review Book Listing';
  }

}

