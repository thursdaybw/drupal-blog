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

## 📘 **README Updates** (Vision & Feature Notes)

### 🔮 Planned Features (Roadmap)

* **Text-to-Speech Overlays**
  Integration with Dia TTS model for generating narration, translation, or voice remastering of segments.

* **Persistent GPU Workflows**
  Use Vast.ai Local Volumes to maintain Whisper models, cache assets, and accelerate queued tasks without redundant transfers.

* **Remote Access & Debugging**
  Cloudflare Tunnel support from Vast.ai’s Instance Portal to expose HTTPS GUIs (e.g., SubtitleOctopus preview, logs).

* **LLM Integrations (Future)**
  Consider LLaMA or similar local LLMs to:

  * Auto-title or summarize transcripts
  * Suggest short-form video cuts
  * Power an “editing copilot” UX

* **Custom Deploy Recipes**
  Potential to offer a user-friendly launch process via tools like Pinokio Desktop or Docker templates.


---

Yes — and you're hitting a crucial architectural fork here:

---

## 🧠 **Browser vs Desktop App: Running Whisper client-side**

Let’s break it down:

---

### ✅ **Browser (WASM Whisper)**

**Pros:**

* No install needed — instant access
* Great for MVP, trials, demos, quick edits
* No OS-specific packaging headaches
* Good privacy story (data never leaves user’s device)
* Easily deployed via your existing Drupal + React stack

**Cons:**

* **Performance limitations**: limited to `tiny` or `base` models
* **Memory limits**: browser caps around 2–4 GB depending on platform
* **Power drain**: CPU-only in most cases; slower than GPU
* **No file system access**: limited to file uploads, localStorage, IndexedDB
* Inconsistent across devices (e.g., iOS Safari is a nightmare for WASM)

---

### ✅ **Desktop App (Electron, Tauri, or native)**

**Pros:**

* Full access to local files, folders, settings
* You can bundle `faster-whisper` or `whisper.cpp` with full models
* Can use GPU acceleration (e.g., with CUDA on supported devices)
* You can run transcription *and* video rendering client-side
* Much smoother for long videos, batch jobs, or drag/drop workflows
* Better crash recovery, offline use, resume, queueing

**Cons:**

* Adds friction: user must install (not ideal for casual/first-time users)
* Need to manage updates, OS-specific bugs
* Harder to debug in the wild
* File system permissions can get gnarly
* You’ll likely want a cloud sync or Drupal integration for shared progress

---

### 🧩 **Your hybrid model idea is gold:**

> “Once I start saving this in entities, a user can switch to a desktop version.”

💡 That’s the smart play.

* User uploads in browser, gets a basic transcript quickly
* If they want to style, re-edit, or queue big jobs — suggest the app
* App can sync to Drupal via API: pulls tasks, uploads results
* Could even have a CLI for pros that mounts the API like a job runner

---

## 🚀 Recommendation:

| Use Case                     | Run Whisper in...                |
| ---------------------------- | -------------------------------- |
| MVP / demo / quick job       | Browser (WASM)                   |
| Paid / pro / long videos     | App (desktop CLI or GUI)         |
| Offline / batch / power user | Desktop                          |
| Mobile?                      | Skip Whisper, use backend or app |

---

## Tests

To run Existing Site tests:
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml html/modules/custom/bevansbench_test/tests/src/ExistingSiteJavascript/

Watching existing site tests:
You don’t actually need to install your own VNC add-on in the web container — the Selenium standalone container that DDEV’s add-on spins up already includes a noVNC endpoint you can watch in your browser. Here’s how to hook into it:
1. Open the noVNC UI

Before you run your mobile test, point your host browser at:

https://bevansbench.com.ddev.site:7900
