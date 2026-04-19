#!/usr/bin/env bash
set -e

APP=ffmpeg-demo
CORE_VER=0.12.15
UTIL_VER=0.12.2

cd /var/www/html

echo "⚙️  Creating React app…"
npx create-react-app $APP
cd $APP

echo "📦 Installing FFmpeg packages…"
npm install @ffmpeg/ffmpeg@$CORE_VER @ffmpeg/util@$UTIL_VER @ffmpeg/core@$CORE_VER

echo "📁 Self-hosting core files…"
mkdir -p public/ffmpeg-core
cp node_modules/@ffmpeg/core/dist/umd/ffmpeg-core.{js,wasm} public/ffmpeg-core/
curl -sL -o public/ffmpeg-core/ffmpeg-core.worker.js \
  https://unpkg.com/@ffmpeg/core@$CORE_VER/dist/umd/ffmpeg-core.worker.js

npm pkg set homepage="/$APP"

npm build

mv build /var/www/html/$APP

