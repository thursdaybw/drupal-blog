(function () {

  function toggleFreePostInEbayTitle(button) {
    const titleInput = document.querySelector('input[name="ebay[ebay_title]"]');
    if (!titleInput) {
      return;
    }

    const currentTitle = normalizeWhitespace(titleInput.value);
    const suffixPattern = /\s+Free Post$/i;

    if (suffixPattern.test(currentTitle)) {
      titleInput.value = currentTitle.replace(suffixPattern, '');
      titleInput.dispatchEvent(new Event('input', { bubbles: true }));
      titleInput.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    const nextTitle = currentTitle === '' ? 'Free Post' : currentTitle + ' Free Post';
    titleInput.value = nextTitle;
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
    titleInput.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function normalizeWhitespace(value) {
    return value.replace(/\s+/g, ' ').trim();
  }

  function humanize(key) {
    const map = {
      'ex_library': 'ex-library markings',
      'gift inscription/pen marks': 'gift inscription or pen marks',
      'foxing': 'foxing',
      'tearing': 'tearing',
      'tanning/toning': 'tanning and toning',
      'edge wear': 'edge wear',
      'dust jacket damage': 'dust jacket damage',
      'surface wear': 'surface wear',
      'paper ageing': 'paper ageing',
      'staining': 'staining'
    };

    return map[key] || key;
  }

  function joinIssues(list) {
    if (list.length === 0) return '';
    if (list.length === 1) return list[0];
    if (list.length === 2) return list[0] + ' and ' + list[1];

    const last = list.pop();
    return list.join(', ') + ' and ' + last;
  }

  function buildNote(issues) {
    const base = 'This item is pre-owned and shows signs of previous use';
    if (issues.length === 0) {
      return base + '. Please see photos for full details.';
    }

    const readable = issues.map(humanize);
    const sentence = joinIssues(readable);

    return base + ' with ' + sentence + '. Please see photos for full details.';
  }

  function updateNote() {
    const checked = Array.from(
      document.querySelectorAll('[data-issue]:checked')
    ).map(el => el.dataset.issue);

    const textarea = document.querySelector(
      'textarea[name="condition[condition_note]"]'
    );

    if (!textarea) return;

    textarea.value = buildNote(checked);
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-issue]')) {
      updateNote();
    }
  });

  document.addEventListener('click', function (e) {
    const button = e.target.closest('[data-ebay-title-toggle="free-post"]');
    if (!button) {
      return;
    }

    toggleFreePostInEbayTitle(button);
  });

})();
