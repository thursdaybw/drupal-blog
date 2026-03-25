(function (Drupal, once) {
  'use strict';

  const thumbnailSelector = '#edit-photos img.image-style-thumbnail';
  const lightboxLinkClass = 'ai-listing-photo-link';
  const preloadedSources = new Set();
  const preloadQueue = [];
  let preloadScheduled = false;

  function deriveOriginalImageUrlFromThumbnail(thumbnailUrl) {
    if (!thumbnailUrl) {
      return '';
    }

    const [pathWithoutQuery] = thumbnailUrl.split('?', 1);
    const stylePrefix = '/styles/thumbnail/public/';
    const prefixIndex = pathWithoutQuery.indexOf(stylePrefix);
    if (prefixIndex === -1) {
      return pathWithoutQuery;
    }

    const leadingPath = pathWithoutQuery.slice(0, prefixIndex);
    const relativeFilePath = pathWithoutQuery.slice(prefixIndex + stylePrefix.length);
    return leadingPath + '/' + relativeFilePath;
  }

  function flushPreloadQueue() {
    preloadScheduled = false;

    while (preloadQueue.length > 0) {
      const source = preloadQueue.shift();
      if (!source || preloadedSources.has(source)) {
        continue;
      }

      preloadedSources.add(source);
      const image = new Image();
      image.decoding = 'async';
      image.fetchPriority = 'low';
      image.src = source;
    }
  }

  function schedulePreload(source) {
    if (!source || preloadedSources.has(source)) {
      return;
    }

    preloadQueue.push(source);
    if (preloadScheduled) {
      return;
    }

    preloadScheduled = true;
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(flushPreloadQueue, {timeout: 1500});
      return;
    }

    window.setTimeout(flushPreloadQueue, 0);
  }

  Drupal.behaviors.aiListingLightbox = {
    attach(context) {
      once('aiListingLightboxWrap', thumbnailSelector, context).forEach((imageElement) => {
        const parentElement = imageElement.parentElement;
        const dataFullSource = imageElement.getAttribute('data-full-src');
        const thumbnailSource = imageElement.getAttribute('src');
        if (!dataFullSource && !thumbnailSource) {
          return;
        }

        const fullImageSource = dataFullSource || deriveOriginalImageUrlFromThumbnail(thumbnailSource);
        if (!fullImageSource) {
          return;
        }

        schedulePreload(fullImageSource);

        if (parentElement && parentElement.tagName === 'A') {
          parentElement.classList.add(lightboxLinkClass);
          parentElement.href = fullImageSource;
          parentElement.setAttribute('data-gallery', 'ai-listing-photos');
          return;
        }

        const linkElement = document.createElement('a');
        linkElement.className = lightboxLinkClass;
        linkElement.href = fullImageSource;
        linkElement.setAttribute('data-gallery', 'ai-listing-photos');

        imageElement.parentNode.insertBefore(linkElement, imageElement);
        linkElement.appendChild(imageElement);
      });

      once('aiListingLightboxInit', 'body', context).forEach(() => {
        GLightbox({
          selector: '.' + lightboxLinkClass,
          zoomable: true,
          draggable: true,
          touchNavigation: true,
          loop: false,
        });
      });
    },
  };
})(Drupal, once);
