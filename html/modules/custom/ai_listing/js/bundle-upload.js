(function (Drupal, once) {
  'use strict';

  var uploadButtonsByInputSelector = {
    'edit-workspace-bundle-upload-file-input': '[data-drupal-selector="edit-workspace-bundle-upload-actions-upload-listing-images"]',
    'edit-workspace-upload-file-input': '[data-drupal-selector="edit-workspace-upload-actions-upload-images"]'
  };

  function findAjaxInstance(button) {
    if (!window.Drupal || !Drupal.ajax || !Array.isArray(Drupal.ajax.instances)) {
      return null;
    }

    for (var index = 0; index < Drupal.ajax.instances.length; index++) {
      var instance = Drupal.ajax.instances[index];
      if (!instance) {
        continue;
      }

      if (instance.element === button) {
        return instance;
      }
    }

    return null;
  }

  function handleDocumentChange(event) {
    var input = event.target;
    var inputSelector = input ? input.getAttribute('data-drupal-selector') : '';

    if (!input || !uploadButtonsByInputSelector[inputSelector]) {
      return;
    }

    console.log('[ai_listing.bundle_upload] file input change', {
      inputId: input.id,
      inputSelector: inputSelector,
      fileCount: input.files ? input.files.length : 0
    });

    if (!input.files || input.files.length === 0) {
      return;
    }

    var button = document.querySelector(uploadButtonsByInputSelector[inputSelector]);
    if (!button) {
      console.log('[ai_listing.bundle_upload] upload button not found', {
        inputId: input.id,
        inputSelector: inputSelector
      });
      return;
    }

    var ajaxInstance = findAjaxInstance(button);
    if (!ajaxInstance) {
      console.log('[ai_listing.bundle_upload] ajax instance not found', {
        buttonId: button.id,
        buttonSelector: button.getAttribute('data-drupal-selector')
      });
      return;
    }

    window.setTimeout(function triggerAjaxUpload() {
      ajaxInstance.eventResponse(button, {
        preventDefault: function preventDefault() {},
        stopPropagation: function stopPropagation() {}
      });
    }, 0);
  }

  function bindDocumentListener() {
    console.log('[ai_listing.bundle_upload] binding document listener');
    document.addEventListener('change', handleDocumentChange, true);
  }

  Drupal.behaviors.aiListingBundleUpload = {
    attach: function attach(context) {
      once('ai-listing-bundle-upload-document', [document.documentElement], context)
        .forEach(function initializeBundleUploadAutomation() {
          bindDocumentListener();
        });
    }
  };
})(Drupal, once);
