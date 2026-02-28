(function (Drupal, once) {
  'use strict';

  var uploadButtonsByInputSelector = {
    'edit-workspace-bundle-upload-file-input': '[data-drupal-selector="edit-workspace-bundle-upload-actions-upload-listing-images"]',
    'edit-workspace-upload-file-input': '[data-drupal-selector="edit-workspace-upload-actions-upload-images"]'
  };
  var panelInputSelectors = {
    'ai-bundle-upload-panel-listing-images': '[data-drupal-selector="edit-workspace-bundle-upload-file-input"]',
    'ai-bundle-upload-panel-item-images': '[data-drupal-selector="edit-workspace-upload-file-input"]'
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

  function findUploadPanel(target) {
    if (!(target instanceof Element)) {
      return null;
    }

    return target.closest('.ai-bundle-upload-panel');
  }

  function findPanelInput(panel) {
    if (!panel) {
      return null;
    }

    var selector = panelInputSelectors[panel.id];
    if (!selector) {
      return null;
    }

    return panel.querySelector(selector) || document.querySelector(selector);
  }

  function setPanelDragState(panel, isActive) {
    if (!panel) {
      return;
    }

    panel.classList.toggle('ai-bundle-upload-panel--dragover', isActive);
  }

  function assignDroppedFiles(input, files) {
    if (!input || !files || files.length === 0) {
      return false;
    }

    try {
      input.files = files;
      return true;
    }
    catch (error) {
      if (typeof DataTransfer === 'undefined') {
        return false;
      }

      var transfer = new DataTransfer();
      Array.from(files).forEach(function appendFile(file) {
        transfer.items.add(file);
      });
      input.files = transfer.files;
      return true;
    }
  }

  function handleDocumentDragEnter(event) {
    var panel = findUploadPanel(event.target);
    setPanelDragState(panel, true);
  }

  function handleDocumentDragOver(event) {
    var panel = findUploadPanel(event.target);
    if (!panel) {
      return;
    }

    event.preventDefault();
    setPanelDragState(panel, true);
  }

  function handleDocumentDragLeave(event) {
    var panel = findUploadPanel(event.target);
    if (!panel) {
      return;
    }

    var relatedTarget = event.relatedTarget;
    if (relatedTarget instanceof Element && panel.contains(relatedTarget)) {
      return;
    }

    setPanelDragState(panel, false);
  }

  function handleDocumentDrop(event) {
    var panel = findUploadPanel(event.target);
    if (!panel) {
      return;
    }

    event.preventDefault();
    setPanelDragState(panel, false);

    var input = findPanelInput(panel);
    if (!input) {
      return;
    }

    if (!assignDroppedFiles(input, event.dataTransfer ? event.dataTransfer.files : null)) {
      return;
    }

    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function bindDocumentListener() {
    console.log('[ai_listing.bundle_upload] binding document listener');
    document.addEventListener('change', handleDocumentChange, true);
    document.addEventListener('dragenter', handleDocumentDragEnter, true);
    document.addEventListener('dragover', handleDocumentDragOver, true);
    document.addEventListener('dragleave', handleDocumentDragLeave, true);
    document.addEventListener('drop', handleDocumentDrop, true);
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
