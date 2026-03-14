(function (Drupal, once) {
  'use strict';

  const tableSelector = 'table[data-drupal-selector="edit-listings"]';
  const mobileCardSelector = '.ai-batch-mobile-card';
  const searchInputSelector = '#ai-listing-search-query';
  const selectedKeysInputSelector = '#ai-batch-selected-listing-keys';
  const selectedCountSelector = '#ai-batch-selected-count';
  const clearSelectionButtonSelector = '#ai-batch-clear-selection';
  const storageKey = 'aiListingLocationBatch.selectedKeys';

  Drupal.behaviors.aiListingLocationBatch = {
    attach(context) {
      once('aiListingLocationBatchForm', 'form', context).forEach((form) => {
        if (!form.querySelector(selectedKeysInputSelector)) {
          return;
        }

        applyStoredSelectionToForm(form);
        synchronizeSelectionFromCurrentForm(form);

        form.addEventListener('click', function (event) {
          if (event.target.closest('a, button, input, label, select, textarea')) {
            return;
          }

          const selectionContainer = event.target.closest('tr, ' + mobileCardSelector);
          if (!selectionContainer || !form.contains(selectionContainer)) {
            return;
          }

          const checkbox = selectionContainer.querySelector('input[type="checkbox"]');
          if (!(checkbox instanceof HTMLInputElement) || extractSelectionKey(checkbox) === '') {
            return;
          }

          checkbox.checked = !checkbox.checked;
          synchronizeSelectionFromCurrentForm(form);
        });

        form.addEventListener('change', function () {
          window.setTimeout(function () {
            synchronizeSelectionFromCurrentForm(form);
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
    document.querySelectorAll('form').forEach(function (form) {
      if (!form.querySelector(selectedKeysInputSelector)) {
        return;
      }

      applyStoredSelectionToForm(form);
      synchronizeHiddenInput(form);
    });

    updateSelectedCountDisplay();
  }

  function synchronizeSelectionFromCurrentForm(form) {
    const selectionKeys = loadStoredSelectionKeys();
    const currentPageCheckboxes = getCurrentPageCheckboxes(form);
    const groupedState = new Map();

    currentPageCheckboxes.forEach(function (checkbox) {
      const selectionKey = extractSelectionKey(checkbox);
      if (selectionKey === '') {
        return;
      }

      const currentValue = groupedState.get(selectionKey) || false;
      groupedState.set(selectionKey, currentValue || checkbox.checked);
    });

    groupedState.forEach(function (isChecked, selectionKey) {
      if (isChecked) {
        selectionKeys.add(selectionKey);
      }
      else {
        selectionKeys.delete(selectionKey);
      }
    });

    saveStoredSelectionKeys(selectionKeys);
    applyStoredSelectionToForm(form);
    synchronizeHiddenInput(form);
    updateSelectedCountDisplay();
  }

  function applyStoredSelectionToForm(form) {
    const selectionKeys = loadStoredSelectionKeys();
    const currentPageCheckboxes = getCurrentPageCheckboxes(form);

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

  function getCurrentPageCheckboxes(form) {
    return Array.from(form.querySelectorAll('tbody input[type="checkbox"], .ai-batch-mobile-card input[type="checkbox"]')).filter(function (checkbox) {
      return checkbox instanceof HTMLInputElement;
    });
  }

  function extractSelectionKey(checkbox) {
    if (!(checkbox instanceof HTMLInputElement)) {
      return '';
    }

    const explicitSelectionKey = checkbox.getAttribute('data-ai-selection-key');
    if (typeof explicitSelectionKey === 'string' && explicitSelectionKey.trim() !== '') {
      return explicitSelectionKey.trim();
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
