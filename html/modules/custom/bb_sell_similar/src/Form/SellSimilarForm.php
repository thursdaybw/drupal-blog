<?php

declare(strict_types=1);

namespace Drupal\bb_sell_similar\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

final class SellSimilarForm extends FormBase {

  public function getFormId(): string {
    return 'bb_sell_similar_sell_similar';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {

    // --- FORM INPUT --------------------------------------------------------
    $form['listing_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('eBay Listing URL'),
      '#required' => TRUE,
      '#size' => 80,
      '#attributes' => [
        'placeholder' => 'https://www.ebay.com.au/itm/123456789012',
        'style' => 'margin-bottom:20px;',
      ],
    ];

    // --- BUTTON RIGHT UNDER INPUT -----------------------------------------
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 10, // ← FORCE IT ABOVE EVERYTHING BELOW
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Go to Sell Similar'),
        '#ajax' => [
          'callback' => '::ajaxSubmit',
        ],
        '#attributes' => [
          'style' => 'margin-top:12px;',
          'style' => 'margin-bottom:25px;',   // ← spacing below button
        ],
      ],
    ];

    // --- MARKETING CONTENT BELOW THE FORM ---------------------------------
$form['marketing'] = [
  '#type' => 'markup',
  '#weight' => 99,
  '#markup' => <<<HTML
<div style="background:#f8f4f0; padding:25px 30px; border-radius:8px; margin-top:30px;">

<img src="/sites/default/files/ebay-payouts-dashboard-thumb.png"
     alt="Dashboard preview"
     style="max-width:280px; float:right; margin:10px 0 20px 30px; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,0.15);">

<h3>👋 Hi! I’m Bevan — full-time Australian eBay bookseller.</h3>

<p>I’m building a tool designed specifically for Australian eBay resellers — especially book sellers.</p>

<p>This isn’t just another generic “reselling helper”. It’s built from the day-to-day reality we all live in:</p>

<ul>
  <li>Listing faster with less hassle</li>
  <li>Cutting through the mess of metrics across eBay, AusPost and your bank</li>
  <li>Automatically tracking stock and where it lives</li>
  <li>Keeping a clean record of payouts, fees and profit</li>
  <li>Creating reminders so nothing slips through the cracks</li>
  <li>Building your own contacts database for repeat buyers</li>
</ul>

<p><strong>It’s not ready yet — but it’s coming, and I’m building it for sellers like us.</strong></p>

<p><strong>If you want updates or early access:</strong></p>

<ul>
  <li>Join my Facebook group:
    <a href="https://www.facebook.com/groups/1159902542964804" target="_blank">
      Reseller’s Bench
    </a>
  </li>
  <li>Message me directly:
    <a href="https://www.facebook.com/bevan.wishart/" target="_blank">
      facebook.com/bevan.wishart
    </a>
  </li>
</ul>

<div style="clear:both;"></div>

</div>
HTML
];

    return $form;
  }



  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = $form_state->getValue('listing_url');
    if (!preg_match('/\/itm\/(\d{9,})/', $url)) {
      $form_state->setErrorByName(
        'listing_url',
        $this->t('That does not look like a valid eBay listing URL.')
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $url = $form_state->getValue('listing_url');

    preg_match('/\/itm\/(\d{9,})/', $url, $matches);
    $item_id = $matches[1];

    $sell_similar_url = sprintf(
      'https://www.ebay.com.au/sl/list?mode=SellLikeItem&itemId=%s',
      $item_id
    );

    header('Location: ' . $sell_similar_url);
    exit;
  }

}


