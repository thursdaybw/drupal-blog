(function (Drupal, once) {
  'use strict';

  const tableSelector = 'table#edit-listings';

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
    },
  };
})(Drupal, once);
