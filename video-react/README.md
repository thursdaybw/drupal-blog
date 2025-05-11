  # Video React App

This is a standalone React app used to preview and process video files in-browser using `ffmpeg.wasm`. It extracts audio client-side and integrates with a Drupal backend for further orchestration.

It was originally created as a pragmatic way to get `ffmpeg.wasm` working reliably in the browser — React was chosen **not for its framework features**, but because other toolchains (Vite, Webpack, etc.) were too fiddly or failed entirely to load the FFmpeg wasm core correctly.

---

## 🧠 Purpose

- Extract audio from uploaded videos **in-browser**
- Avoid uploading large video files unnecessarily
- Preview video + generated audio before involving any server
- Later upload audio to a Drupal-driven backend that spins up a Vast.ai GPU instance for Whisper transcription

---

## 🏗️ Project Layout

| Path                                            | Description                                                                                         |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `/var/www/html/video-react/`                    | React project root – contains source, config, and build output                                      |
| `/var/www/html/video-react/src/`                | React source code (main app logic, e.g. `App.js`)                                                   |
| `/var/www/html/video-react/public/ffmpeg-core/` | FFmpeg WASM binaries (manually copied from `node_modules`)                                          |
| `/var/www/html/video-react/build/`              | Generated static output after `npm run build`                                                       |
| `/var/www/html/html/video-react/`               | Drupal web root — this is where the `build/` contents get copied to and served from `/video-react/` |

---

⚙️ FFmpeg Integration

We use @ffmpeg/ffmpeg, @ffmpeg/util, and @ffmpeg/core to run FFmpeg in the browser via WebAssembly.

Getting this working reliably only succeeded inside a React app scaffolded with Create React App. Other setups (plain npm, Vite, Webpack) failed to correctly load the FFmpeg WASM runtime.
🧩 Setup Steps (inside the React app)

    From inside the React project root (/var/www/html/video-react/), we installed the required packages:

npm install @ffmpeg/ffmpeg@0.12.15 @ffmpeg/util@0.12.2 @ffmpeg/core@0.12.15

Then we manually copied the FFmpeg WASM runtime files from node_modules into the public folder so they would be included in the production build:

    mkdir public/ffmpeg-core
    cp node_modules/@ffmpeg/core/dist/umd/ffmpeg-core.{js,wasm} public/ffmpeg-core/
    curl -sL -o public/ffmpeg-core/ffmpeg-core.worker.js \
      https://unpkg.com/@ffmpeg/core@0.12.15/dist/umd/ffmpeg-core.worker.js

These are now served at runtime from /video-react/ffmpeg-core/ in the browser, allowing FFmpeg to run entirely on the client.

This allows the app to load FFmpeg WASM at runtime, without relying on a CDN.

All FFmpeg core files are included in the public/ folder so they’re automatically copied into build/ffmpeg-core/ when we run npm run build.
🧩 Drupal Integration

The React app is served from a subfolder of the Drupal web root:

/var/www/html/html/video-react/

Which maps to:

https://bevansbench.com.ddev.site/video-react/

This is crucial — serving the app from the same origin as Drupal allows it to:

    Share Drupal’s session cookie

    Skip CORS issues entirely

    Make authenticated requests to /jsonapi, /user/login, etc.

The Drupal webroot is:

/var/www/html/html/

The React project root (source code and package.json) lives at:

/var/www/html/video-react/

⚡ Dev Workflow

To work on the React app, go to the project root:

cd /var/www/html/video-react

Then run:

npm start

This launches the React dev server at:

http://localhost:3000

⚠️ Important Caveats:

    npm start must be run on the host machine, not inside DDEV. So you’ll need Node.js installed on your host.

    React's dev server does not share cookies with DDEV’s domain, so session-based requests won’t work.

    You’ll see your changes hot-reload — but you can’t test authentication or real Drupal interactions from here.

🧹 To deploy updated changes into Drupal:

Instead of relying on npm start, use the DDEV command:

ddev build-react

This command:

    Runs npm run build

    Deletes all files inside /html/video-react/

    Copies the latest build/* contents from your React project into Drupal’s web root

The command lives at:

.ddev/commands/web/build-react

After running it, your app is live at:

https://bevansbench.com.ddev.site/video-react/

🧠 Notes and Frustrations

    You can forward ports or set up proxying so the React dev server talks to Drupal, but honestly? It’s a mess.

    We’re intentionally keeping it simple for now:

        Run ddev build-react after changes

        Eventually replace hot reloading with a proper file watcher (chokidar, nodemon, or npm run watch)

    This app is meant to live inside Drupal, so npm start is temporary. It will likely stop being useful once authenticated and backend-dependent features are added.

Yeah, frontend dev is kind of a pain — but this path works, and we’ve got it under control.

🤖 Why React?

    FFmpeg WASM failed to load reliably in every other stack (plain npm, Vite, Webpack)

    Create React App just worked, and let us install the @ffmpeg/core modules and host the necessary files

    React is not core to this project — it’s a means to an end

📝 Future Plans

    Use IndexedDB to persist uploaded videos across refresh

    Add logic to upload extracted audio to a Vast.ai server for Whisper transcription

    Return subtitle files (.ass) and render them live with SubtitlesOctopus or burn-in

🧼 Deployment Notes

This app is not deployed to production. It is currently:

    Built and served entirely inside DDEV

    Meant for preview/testing/local integration with Drupal

    Deployment outside of DDEV (e.g., on prod) will require static hosting + backend endpoint coordination


