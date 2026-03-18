(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiListingBulkIntakeSets = {
    attach(context) {
      once('ai-listing-bulk-intake-sets', '#ai-bulk-intake-sets-root', context).forEach((root) => {
        const addButton = root.querySelector('[data-ai-bulk-intake-add-set]');
        if (!addButton) {
          return;
        }

        addButton.addEventListener('click', function () {
          const rows = root.querySelectorAll('[data-ai-bulk-intake-set-row]');
          const nextIndex = rows.length + 1;

          const row = document.createElement('div');
          row.setAttribute('data-ai-bulk-intake-set-row', String(nextIndex));
          row.style.marginBottom = '14px';

          const label = document.createElement('label');
          label.textContent = 'Set ' + nextIndex + ' images';
          label.style.display = 'block';
          label.style.fontWeight = '600';
          label.style.marginBottom = '6px';

          const input = document.createElement('input');
          input.type = 'file';
          input.name = 'intake_sets[set_' + nextIndex + '][]';
          input.multiple = true;
          input.accept = 'image/*';
          input.style.display = 'block';

          row.appendChild(label);
          row.appendChild(input);
          addButton.parentElement.insertBefore(row, addButton);
        });
      });
    },
  };
})(Drupal, once);
