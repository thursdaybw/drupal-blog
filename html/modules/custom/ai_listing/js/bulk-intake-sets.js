(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiListingBulkIntakeSets = {
    attach(context) {
      once('ai-listing-bulk-intake-sets', '#ai-bulk-intake-sets-root', context).forEach((root) => {
        const createSetRow = function (nextIndex) {
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
          input.classList.add('ai-bulk-intake-file-input');

          row.appendChild(label);
          row.appendChild(input);
          root.appendChild(row);
          return row;
        };

        const ensureNextRow = function () {
          const rows = Array.from(root.querySelectorAll('[data-ai-bulk-intake-set-row]'));
          if (rows.length === 0) {
            createSetRow(1);
            return;
          }
          const lastRow = rows[rows.length - 1];
          const lastInput = lastRow.querySelector('input[type="file"]');
          if (!lastInput) {
            createSetRow(rows.length + 1);
            return;
          }
          if (lastInput.files && lastInput.files.length > 0) {
            createSetRow(rows.length + 1);
          }
        };

        root.addEventListener('change', function (event) {
          const target = event.target;
          if (!target || target.tagName !== 'INPUT' || target.type !== 'file') {
            return;
          }
          if (target.files && target.files.length > 0) {
            ensureNextRow();
          }
        });

        ensureNextRow();
      });
    },
  };
})(Drupal, once);
