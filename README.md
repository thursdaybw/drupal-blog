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
ddev drush sql-drop -y && ssh root@myhost "cd /root/workspace/drupal-blog && docker-compose exec -T -u www-data appserver bash -c \"cd /var/www && ./vendor/bin/drush sql-dump --gzip\"" |gunzip |ddev drush sqlc && ddev drush cr

# Sync site files
rsync -avz -e "ssh" --progress root@85.31.234.104:/root/workspace/drupal-blog/html/sites/default/files/ ./html/sites/default/files/
```

## Loging with bash
```
docker run drupal-blog_appserver_1 -it /bin/bash
```

Sure — here’s a section you can drop into your project’s README to document how cron is configured on your **production VPS**:

---

## 🕰️ Cron Configuration (Production)

On the production server, Drupal's cron is **triggered externally** via the host system's crontab. It runs every minute using the following entry:

```cron
* * * * * docker exec -i drupal-blog_appserver_1 /var/www/vendor/bin/drush -r /var/www/html cron > /dev/null 2>&1
```

### 💡 Notes

* This cron job runs **outside the container**, from the VPS host.
* It uses `docker exec` to call `drush cron` inside the running Drupal container.
* Output is silenced via `> /dev/null 2>&1`.

### 🔧 To Disable Temporarily

You can disable cron (e.g., for manual queue testing) by commenting it out:

```bash
crontab -e
```

Then change:

```cron
* * * * * docker exec ...
```

To:

```cron
# * * * * * docker exec ...
```

Save and exit. Cron will no longer run automatically until you uncomment the line.

---




Sample video editing schema:
```
{
  "tracks": [
    {
      "type": "video",
      "src": "clip.mp4"
    },
    {
      "type": "image",
      "src": "logo.png",
      "effect": "pulse",
      "position": { "x": "5%", "y": "-10%" }
    },
    {
      "type": "subtitles",
      "src": "captions.ass"
    }
  ]
}
```


### How to change the faster-whisper model in vast ai:

✅ How to change the model

Build with default (small) or override like:

docker build --build-arg WHISPER_MODEL=base -t bevanw/faster-whisper:base .

Or change it at runtime (Vast doesn’t support this, but you can test it locally):

docker run -e WHISPER_MODEL=medium ...


