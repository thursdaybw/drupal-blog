(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiStockCullPicker = {
    attach(context) {
      once('aiStockCullPickerSelectAll', '.ai-stock-cull-picker__select-all', context).forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const locationKey = checkbox.dataset.locationKey || '';
          if (!locationKey) {
            return;
          }

          document.querySelectorAll('.ai-stock-cull-picker__row-checkbox[data-location-key="' + locationKey + '"]').forEach((rowCheckbox) => {
            rowCheckbox.checked = checkbox.checked;
          });
        });
      });
    },
  };
})(Drupal, once);
