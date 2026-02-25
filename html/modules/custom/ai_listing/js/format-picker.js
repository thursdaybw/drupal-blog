(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiListingFormatPicker = {
    attach(context) {
      once('aiListingFormatPicker', '[data-ai-format-picker]', context).forEach((wrapper) => {
        const input = wrapper.querySelector('input[type="text"]');
        wrapper.addEventListener('click', (event) => {
          const button = event.target.closest('.ai-format-suggestion');
          if (!button) {
            return;
          }
          if (!input) {
            return;
          }
          input.value = button.dataset.formatValue || '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
        });
      });
    },
  };
})(Drupal, once);
