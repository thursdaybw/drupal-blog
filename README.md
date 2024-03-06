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
git pull origin main
docker-compose down && docker-compose build && docker-compose up -d
docker-compose exec appserver /bin/bash
cd ..
composer install
./vendor/bin/drush cim -y
```

## Loging with bash
```
docker run drupal-blog_appserver_1 -it /bin/bash
```

