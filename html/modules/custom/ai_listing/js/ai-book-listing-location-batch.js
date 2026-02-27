(function (Drupal, once) {
  'use strict';

  const tableSelector = 'table[data-drupal-selector="edit-listings"]';
  const searchInputSelector = '#ai-listing-search-query';

  Drupal.behaviors.aiListingLocationBatch = {
    attach(context) {
      once('aiListingLocationBatch', tableSelector, context).forEach((table) => {
        table.addEventListener('click', (event) => {
          const row = event.target.closest('tr');
          if (!row || !table.contains(row)) {
            return;
          }

          const checkbox = row.querySelector('input[type="checkbox"]');
          if (!checkbox) {
            return;
          }

          checkbox.checked = !checkbox.checked;
          table.dispatchEvent(new Event('change'));
        });
      });

      once('aiListingLocationBatchSearchEnter', searchInputSelector, context).forEach((input) => {
        input.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter') {
            return;
          }

          event.preventDefault();
          event.stopPropagation();

          const form = input.closest('form');
          if (!form) {
            return;
          }

          const applyButton = form.querySelector('#ai-listing-apply-filters');
          if (applyButton instanceof HTMLInputElement || applyButton instanceof HTMLButtonElement) {
            if (typeof form.requestSubmit === 'function') {
              form.requestSubmit(applyButton);
            }
            else {
              applyButton.click();
            }
          }
        });

        const form = input.closest('form');
        if (!form) {
          return;
        }

        form.addEventListener(
          'keydown',
          (event) => {
            if (event.key !== 'Enter') {
              return;
            }
            if (event.target !== input) {
              return;
            }

            event.preventDefault();
            event.stopPropagation();
          },
          true,
        );
      });
    },
  };
})(Drupal, once);
