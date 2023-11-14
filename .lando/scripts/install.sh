# Wait for the database to be ready
until mysqladmin ping -hdatabase -P3306 --silent; do
  echo "Waiting for database to be ready..."
  sleep 4 
done
echo "Database is ready."
./vendor/bin/drush site:install --existing-config -y --site-name="Thursday Blog" minimal
./vendor/bin/drush uli
