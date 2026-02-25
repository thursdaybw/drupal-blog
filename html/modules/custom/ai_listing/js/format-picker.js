(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiListingFormatPicker = {
    attach(context) {
      once('aiListingFormatPicker', '[data-ai-format-picker]', context).forEach((wrapper) => {
        const input = wrapper.querySelector('input[type="text"]');
        const ebayTitle = document.querySelector('input[name="ebay[ebay_title]"]');
        const features = document.querySelector('textarea[name="features"]');

        wrapper.addEventListener('click', (event) => {
          const button = event.target.closest('.ai-format-suggestion');
          if (!button || !input) {
            return;
          }

          const newFormat = button.dataset.formatValue || '';
          const oldFormat = input.value;
          input.value = newFormat;
          input.dispatchEvent(new Event('input', { bubbles: true }));

          if (ebayTitle) {
            const match = /^(.+ by .+ )(.+?) book$/i.exec(ebayTitle.value.trim());
            if (match) {
              ebayTitle.value = `${match[1]}${newFormat} book`;
              ebayTitle.dispatchEvent(new Event('input', { bubbles: true }));
            }
          }

          if (features && oldFormat) {
            const lines = features.value.split('\\n');
            let changed = false;
            for (let i = 0; i < lines.length; i++) {
              if (lines[i].trim() === oldFormat) {
                lines[i] = newFormat;
                changed = true;
              }
            }
            if (changed) {
              features.value = lines.join('\\n');
              features.dispatchEvent(new Event('input', { bubbles: true }));
            }
          }
        });
      });
    },
  };
})(Drupal, once);
