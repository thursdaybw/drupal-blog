(function () {

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

})();
