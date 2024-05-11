## Initial Setup and History

Nice tips for lando setup https://evolvingweb.com/working-drupal-lando
specifically:
```
To create the project we'll use the official composer template for Drupal, it can be found in https://github.com/drupal/recommended-project. To create your project, you should run this command:

lando composer create-project drupal/recommended-project my-project

That command will download Drupal core and dependencies into a my-project subfolder, so you need to move them to the root of your project:

mv my-project/* .

mv my-project/.* .

rmdir my-project
```
handy command to deal the composer and lando both wanting to create the project directory.


## Deploy new code updates
```
cd /root/workspace/drupal-blog
sudo chown -R root:root .
git pull origin main
sudo chown -R www-data:www-data .
docker-compose down && docker-compose build && docker-compose up -d
docker-compose exec -u www-data appserver bash -c "cd /var/www && composer install && ./vendor/bin/drush cim -y"
```

## Restore the production database and sitefiles to local
```
# Restore the DB
lando drush sql-drop -y && ssh root@myhost "cd /root/workspace/drupal-blog && docker-compose exec -T -u www-data appserver bash -c \"cd /var/www && ./vendor/bin/drush sql-dump --gzip\"" |gunzip |lando drush sqlc && lando drush cr
ddev drush sql-drop -y && ssh root@myhost "cd /root/workspace/drupal-blog && docker-compose exec -T -u www-data appserver bash -c \"cd /var/www && ./vendor/bin/drush sql-dump --gzip\"" |gunzip |ddev drush sqlc && lando drush cr

# Sync site files
rsync -avz -e "ssh" --progress root@85.31.234.104:/root/workspace/drupal-blog/html/sites/default/files/ ./html/sites/default/files/
```

## Loging with bash
```
docker run drupal-blog_appserver_1 -it /bin/bash
```

