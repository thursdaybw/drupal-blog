(function (Drupal, once) {

  Drupal.behaviors.aiListingLightbox = {
    attach: function (context) {

      once('aiListingLightbox', '.ai-listing-photo-link', context)
        .forEach(function () {

          const lightbox = GLightbox({
            selector: '.ai-listing-photo-link',
            zoomable: true,
            draggable: true,
            touchNavigation: true,
            loop: false
          });

        });

    }
  };

})(Drupal, once);
