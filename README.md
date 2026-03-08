## Initial Setup and History

## Local Development Setup (DDEV)

Canonical documentation for local setup now lives here:

- [docs/dev/README.md](docs/dev/README.md)

Troubleshooting notes live here:

- [docs/dev/TROUBLESHOOTING.md](docs/dev/TROUBLESHOOTING.md)

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
### Run a more specific test class or method:
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml -vv --filter testCaptionsPageLoggedIn html/modules/custom/bevansbench_test/tests/src/ExistingSiteJavascript/GenerateCaptionsMobileTest.php
ddev exec vendor/bin/phpunit -c /var/www/html/phpunit.browser.xml html/modules/contrib/video_forge/tests/src/Functional/VideoUploadControllerTest.php


Watching existing site tests:
You don’t actually need to install your own VNC add-on in the web container — the Selenium standalone container that DDEV’s add-on spins up already includes a noVNC endpoint you can watch in your browser. Here’s how to hook into it:
1. Open the noVNC UI

Before you run your mobile test, point your host browser at:

https://bevansbench.com.ddev.site:7900


Todos:

Yes mate, you’re thinking exactly right — the big end-to-end test is valuable, but it’s a blunt instrument:

* It only catches *this race* if the timing + file size align.
* It can’t cover all the subtle edge cases we’ve now uncovered.

Whereas unit and kernel tests can target the brittle seams we identified.

---

### 🎯 Areas worth kernel/unit tests (based on what we saw today)

1. **TaskStateService::markTranscriptReady()**

   * Unit/kernel test:

     * Given a task with a `video` set, when `markTranscriptReady()` runs, the video reference is *preserved*.
     * Given a task without a video, transcript fields are still set.
   * This locks in the fix we added (so nobody “simplifies” it later and re-introduces the bug).

2. **VideoUploadController::upload()**

   * Kernel test (no JS/browser needed):

     * Simulate chunked upload finalization.
     * Assert it creates `File`, `Media`, and attaches media to an existing `video_forge_tasks` row.
     * Assert it logs Before/After save.
   * This avoids the E2E timing flakiness — you’re testing the core logic directly.

3. **TaskStateService::initializeTask()**

   * Kernel test:

     * When a media already exists for the `video_ref_id`, the new task gets linked to that media.
     * When no media exists, task starts unlinked.

4. **Concurrency simulation (clobber protection)**

   * Unit test (no DB needed, you can mock storage):

     * Fake an entity with `video` set.
     * Call `markTranscriptReady()` and assert `video` survives.
     * That isolates the “field preservation” behaviour without queues/cron/upload noise.

5. **ProvisioningService / TranscriptionProvisionWorker**

   * Kernel test:

     * Enqueue a dummy task ID.
     * Run the worker.
     * Assert it calls `provision()` → `transcribe()` sequence, and updates task status.
   * Doesn’t need real Vast.ai or SSH; you can mock the `RemoteSessionServiceFactory` and `AudioUploadService`.

---

### 📐 Why kernel vs unit here

* **Unit**: For logic you can isolate (e.g. preserve `video` field).
* **Kernel**: For things that depend on entity storage (`File`, `Media`, `video_forge_tasks`).
* **Functional/Browser**: Only for the real integration path (upload form → React app → queue). You already have that.

---

### 🔨 Concrete examples

* **Kernel test for `markTranscriptReady`**:

  ```php
  $task = $this->createTaskWithVideo($media_id);
  $this->taskState->markTranscriptReady($task->uuid(), $json, $ass, 'hello');
  $task = $this->reloadTask($task);
  $this->assertEquals($media_id, $task->get('video')->target_id);
  ```

* **Kernel test for `VideoUploadController`**:

  ```php
  $request = Request::create("/video-forge/upload-video", 'POST', [
    'video_id' => $uuid,
    'upload_id' => 'x',
    'index' => 0,
    'total' => 1,
  ], [], ['file' => $fakeFile]);

  $response = $this->controller->upload($request);
  $this->assertEquals(200, $response->getStatusCode());
  $task = $this->loadTaskByVideoId($uuid);
  $this->assertNotEmpty($task->get('video')->target_id);
  ```
