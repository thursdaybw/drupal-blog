(function () {

      const BARGAIN_HEADER_HTML = `
  <div style="text-align:left; margin:10px 0;">
    <img src="https://www.bevansbench.com/sites/default/files/2025-09/4e866541-d8d7-4bf0-8851-141e68cbdc08.png" alt="Quick Tip: Request total from seller at checkout" style="width:100%; max-width:600px; height:auto;">
  </div>
  <p>📚 <strong>BARGAIN BIN SPECIAL</strong> 📚</p>
  <p>Grab a deal from <em><a href="https://www.ebay.com.au/str/bevansbench/Bargain-Bin/_i.html?store_cat=85529649013">Bevan's Bench Bargain Bin</a></em>— quality used books at just $1.99 each ($2.99 for larger books). Same great care in packaging and dispatch as every order.</p>
  <p>🛒 <strong>Mix & Match & Save</strong></p>
  <ul style="margin:0 0 1em 1.25em; padding:0;">
    <li>Buy 5 or more and get 45% off your entire Bargain Bin order</li>
    <li>Only $2 shipping for each additional item</li>
    <li>Add as many as you like to your cart — eBay calculates the discount automatically!</li>
  </ul>
  <p>🛒 <a href="https://www.ebay.com.au/str/bevansbench/Bargain-Bin/_i.html?store_cat=85529649013">Browse the full Bargain Bin</a></p>
  `;

  function getEditor() {

    const textarea = document.querySelector('textarea[name="ebay[description][value]"]');

    if (!textarea) {
      console.log('Textarea not found.');
      return null;
    }

    const editorId = textarea.getAttribute('data-ckeditor5-id');

    if (!editorId) {
      console.log('No CKEditor ID found.');
      return null;
    }

    if (!Drupal.CKEditor5Instances) {
      console.log('CKEditor5Instances not ready.');
      return null;
    }

    return Drupal.CKEditor5Instances.get(editorId) || null;
  }

  function applyPreset() {

    const editor = getEditor();
    if (!editor) {
      console.log('Editor instance not found.');
      return;
    }

    const currentData = editor.getData();

    if (currentData.includes('BARGAIN BIN SPECIAL')) {
      return;
    }

    editor.setData(BARGAIN_HEADER_HTML + currentData);
  }

  document.addEventListener('click', function (e) {
    const button = e.target.closest('#apply-bargain-bin');
    if (!button) return;

    e.preventDefault();
    applyPreset();
  });

})();
