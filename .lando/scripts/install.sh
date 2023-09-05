drush site:install -y standard install_configure_form.enable_update_status_emails=NULL --account-pass=admin --site-name="Drupal Blog"
drush en -y drupal_blog
drush cr
drush uli
