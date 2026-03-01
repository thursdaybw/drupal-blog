(function (Drupal, once) {
  'use strict';

  const tableSelector = 'table[data-drupal-selector="edit-listings"]';
  const searchInputSelector = '#ai-listing-search-query';
  const selectedKeysInputSelector = '#ai-batch-selected-listing-keys';
  const selectedCountSelector = '#ai-batch-selected-count';
  const clearSelectionButtonSelector = '#ai-batch-clear-selection';
  const storageKey = 'aiListingLocationBatch.selectedKeys';

  Drupal.behaviors.aiListingLocationBatch = {
    attach(context) {
      once('aiListingLocationBatch', tableSelector, context).forEach((table) => {
        applyStoredSelectionToTable(table);
        synchronizeSelectionFromCurrentPage(table);

        table.addEventListener('click', function (event) {
          if (event.target.closest('a, button, input, label, select, textarea')) {
            return;
          }

          const row = event.target.closest('tr');
          if (!row || !table.contains(row)) {
            return;
          }

          const checkbox = row.querySelector('tbody input[type="checkbox"]');
          if (!(checkbox instanceof HTMLInputElement)) {
            return;
          }

          checkbox.checked = !checkbox.checked;
          synchronizeSelectionFromCurrentPage(table);
        });

        table.addEventListener('change', function () {
          window.setTimeout(function () {
            synchronizeSelectionFromCurrentPage(table);
          }, 0);
        });
      });

      once('aiListingLocationBatchFormSubmit', 'form', context).forEach((form) => {
        if (!form.querySelector(selectedKeysInputSelector)) {
          return;
        }

        form.addEventListener('submit', function () {
          synchronizeHiddenInput(form);
        });
      });

      once('aiListingLocationBatchClearSelection', clearSelectionButtonSelector, context).forEach((button) => {
        button.addEventListener('click', function (event) {
          event.preventDefault();

          clearStoredSelection();
          updateSelectionStateForDocument();
        });
      });

      once('aiListingLocationBatchSearchEnter', searchInputSelector, context).forEach((input) => {
        input.addEventListener('keydown', function (event) {
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
            synchronizeHiddenInput(form);

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
          function (event) {
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

      updateSelectionStateForDocument();
    },
  };

  function updateSelectionStateForDocument() {
    document.querySelectorAll(tableSelector).forEach(function (table) {
      applyStoredSelectionToTable(table);
    });

    document.querySelectorAll('form').forEach(function (form) {
      if (!form.querySelector(selectedKeysInputSelector)) {
        return;
      }

      synchronizeHiddenInput(form);
    });

    updateSelectedCountDisplay();
  }

  function synchronizeSelectionFromCurrentPage(table) {
    const selectionKeys = loadStoredSelectionKeys();
    const currentPageCheckboxes = getCurrentPageCheckboxes(table);

    currentPageCheckboxes.forEach(function (checkbox) {
      const selectionKey = extractSelectionKey(checkbox);
      if (selectionKey === '') {
        return;
      }

      if (checkbox.checked) {
        selectionKeys.add(selectionKey);
        return;
      }

      selectionKeys.delete(selectionKey);
    });

    saveStoredSelectionKeys(selectionKeys);

    const form = table.closest('form');
    if (form) {
      synchronizeHiddenInput(form);
    }

    updateSelectedCountDisplay();
  }

  function applyStoredSelectionToTable(table) {
    const selectionKeys = loadStoredSelectionKeys();
    const currentPageCheckboxes = getCurrentPageCheckboxes(table);

    currentPageCheckboxes.forEach(function (checkbox) {
      const selectionKey = extractSelectionKey(checkbox);
      checkbox.checked = selectionKey !== '' && selectionKeys.has(selectionKey);
    });
  }

  function synchronizeHiddenInput(form) {
    const hiddenInput = form.querySelector(selectedKeysInputSelector);
    if (!(hiddenInput instanceof HTMLInputElement)) {
      return;
    }

    const selectedKeys = Array.from(loadStoredSelectionKeys());
    hiddenInput.value = JSON.stringify(selectedKeys);
  }

  function updateSelectedCountDisplay() {
    const selectedCountElement = document.querySelector(selectedCountSelector);
    if (!selectedCountElement) {
      return;
    }

    selectedCountElement.textContent = String(loadStoredSelectionKeys().size);
  }

  function clearStoredSelection() {
    window.sessionStorage.removeItem(storageKey);
  }

  function loadStoredSelectionKeys() {
    const serializedValue = window.sessionStorage.getItem(storageKey);
    if (typeof serializedValue !== 'string' || serializedValue.trim() === '') {
      return new Set();
    }

    try {
      const decoded = JSON.parse(serializedValue);
      if (!Array.isArray(decoded)) {
        return new Set();
      }

      const keys = decoded
        .filter(isNonEmptyString)
        .map(function (value) {
          return value.trim();
        });

      return new Set(keys);
    }
    catch (error) {
      return new Set();
    }
  }

  function saveStoredSelectionKeys(selectionKeys) {
    const values = Array.from(selectionKeys);
    window.sessionStorage.setItem(storageKey, JSON.stringify(values));
  }

  function getCurrentPageCheckboxes(table) {
    return Array.from(table.querySelectorAll('tbody input[type="checkbox"]')).filter(function (checkbox) {
      return checkbox instanceof HTMLInputElement;
    });
  }

  function extractSelectionKey(checkbox) {
    if (!(checkbox instanceof HTMLInputElement)) {
      return '';
    }

    const matches = checkbox.name.match(/^listings\[(.+)\]$/);
    if (!matches || !matches[1]) {
      return '';
    }

    return matches[1];
  }

  function isNonEmptyString(value) {
    return typeof value === 'string' && value.trim() !== '';
  }
})(Drupal, once);
