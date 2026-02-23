(function (Drupal, once) {

  Drupal.behaviors.aiListingPhotoViewer = {
    attach: function (context) {

      once('aiListingPhotoViewer', '.ai-listing-photo-link', context)
        .forEach(function (el) {

          el.addEventListener('click', function (e) {
            e.preventDefault();

            const src = el.getAttribute('href');

            const overlay = document.createElement('div');
            overlay.className = 'ai-photo-overlay';

            const wrapper = document.createElement('div');
            wrapper.className = 'ai-photo-wrapper';

            const img = document.createElement('img');
            img.src = src;

            wrapper.appendChild(img);
            overlay.appendChild(wrapper);
            document.body.appendChild(overlay);

            document.body.style.overflow = 'hidden';

            let scale = 1;
            let panX = 0;
            let panY = 0;
            let isDragging = false;
            let startX = 0;
            let startY = 0;

            function updateTransform() {
              img.style.transform =
                `translate(${panX}px, ${panY}px) scale(${scale})`;
            }

            img.onload = function () {
              const vw = window.innerWidth;
              const vh = window.innerHeight;

              const widthRatio = vw / img.naturalWidth;
              const heightRatio = vh / img.naturalHeight;

              scale = Math.min(widthRatio, heightRatio, 1);

              panX = 0;
              panY = 0;

              updateTransform();
            };

            // Zoom anchored to cursor
            wrapper.addEventListener('wheel', function (event) {
              event.preventDefault();

              const rect = img.getBoundingClientRect();
              const offsetX = event.clientX - rect.left;
              const offsetY = event.clientY - rect.top;

              const zoomFactor = event.deltaY < 0 ? 1.1 : 0.9;
              const newScale = scale * zoomFactor;

              if (newScale < 0.1 || newScale > 10) return;

              panX -= (offsetX / scale) * (zoomFactor - 1);
              panY -= (offsetY / scale) * (zoomFactor - 1);

              scale = newScale;

              updateTransform();
            });

            // Drag to pan
            wrapper.addEventListener('mousedown', function (event) {
              isDragging = true;
              startX = event.clientX - panX;
              startY = event.clientY - panY;
              wrapper.style.cursor = 'grabbing';
            });

            window.addEventListener('mousemove', function (event) {
              if (!isDragging) return;

              panX = event.clientX - startX;
              panY = event.clientY - startY;

              updateTransform();
            });

            window.addEventListener('mouseup', function () {
              isDragging = false;
              wrapper.style.cursor = 'grab';
            });

            function closeOverlay() {
              document.body.style.overflow = '';
              document.removeEventListener('keydown', keyHandler);
              overlay.remove();
            }

            function keyHandler(event) {
              if (event.key === 'Escape') {
                closeOverlay();
              }
            }

            document.addEventListener('keydown', keyHandler);

            overlay.addEventListener('click', function (e) {
              if (e.target === overlay) {
                closeOverlay();
              }
            });

          });

        });

    }
  };

})(Drupal, once);
