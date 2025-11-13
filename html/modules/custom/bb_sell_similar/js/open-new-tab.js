(function (Drupal) {
  Drupal.AjaxCommands.prototype.openNewTab = function (ajax, response, status) {
    window.open(response.url, '_blank');
  };
})(Drupal);

