(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiListingIntakePicker = {
    attach(context) {
      once('ai-listing-intake-picker', '.ai-intake-picker-checkbox', context).forEach((checkbox) => {
        checkbox.addEventListener('click', function (event) {
          const table = checkbox.closest('table');
          if (!table) {
            return;
          }

          const checkboxes = Array.from(table.querySelectorAll('.ai-intake-picker-checkbox'));
          const index = checkboxes.indexOf(checkbox);
          if (index < 0) {
            return;
          }

          const lastIndex = Number(table.dataset.lastIntakeCheckboxIndex ?? -1);
          if (event.shiftKey && lastIndex >= 0 && lastIndex !== index) {
            const start = Math.min(lastIndex, index);
            const end = Math.max(lastIndex, index);
            for (let i = start; i <= end; i++) {
              checkboxes[i].checked = checkbox.checked;
            }
          }

          table.dataset.lastIntakeCheckboxIndex = String(index);
        });
      });
    },
  };
})(Drupal, once);
