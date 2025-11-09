import { useState } from 'react';

const STATE_KEY = 'videoUploadState';

export function useVideoUpload({ setStatus }) {
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadComplete, setUploadComplete] = useState(false);
  const [uploadError, setUploadError] = useState(null);

  const startUpload = async (videoFile, videoId, taskId) => {
    console.log('[useVideoUpload] startUpload called');

    if (!videoFile || !videoId) return;

    setStatus?.('📤 Uploading video…');
    setUploadProgress(0);
    setUploadComplete(false);
    setUploadError(null);

    try {
      const chunkSize = 1 * 1024 * 1024;
      const total = Math.ceil(videoFile.size / chunkSize);

      // try to resume
      const saved = JSON.parse(localStorage.getItem(STATE_KEY) || '{}');
      let uploadId;
      let resumeIndex = 0;
      if (saved.videoId === videoId && saved.uploadId) {
        uploadId = saved.uploadId;
        resumeIndex = saved.index + 1;
        console.log(`[useVideoUpload] Resuming from chunk ${resumeIndex + 1}`);
      } else {
        uploadId = crypto.randomUUID?.() || String(Date.now());
      }

      for (let index = resumeIndex; index < total; index++) {
        const start = index * chunkSize;
        const end = Math.min(start + chunkSize, videoFile.size);
        const blob = videoFile.slice(start, end);

        const formData = new FormData();
        formData.append('file', blob, `part-${index}.bin`);
        let uploadUrl = `/video-forge/upload-video?video_id=${videoId}&upload_id=${uploadId}&index=${index}&total=${total}`;
        if (taskId) uploadUrl += `&task_id=${taskId}`;

        const controller = new AbortController();
        const visibilityHandler = () => {
          if (document.hidden) controller.abort();
        };
        document.addEventListener('visibilitychange', visibilityHandler);

        try {
          const res = await fetch(uploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'include',
            signal: controller.signal,
          });
          if (!res.ok) throw new Error(`status ${res.status}`);
        } catch (err) {
          if (err.name === 'AbortError') {
            console.warn('[useVideoUpload] Aborted due to tab hidden');
            setStatus?.('⏸ Upload paused');

            // automatically resume when tab becomes visible again
            const resumeHandler = () => {
              if (!document.hidden) {
                document.removeEventListener('visibilitychange', resumeHandler);
                console.log('[useVideoUpload] Resuming upload after tab restore');
                startUpload(videoFile, videoId, taskId);
              }
            };
            document.addEventListener('visibilitychange', resumeHandler, { once: true });
            document.removeEventListener('visibilitychange', visibilityHandler);
            return;
          }
        }

        document.removeEventListener('visibilitychange', visibilityHandler);

        // save state every successful chunk
        localStorage.setItem(
          STATE_KEY,
          JSON.stringify({ uploadId, videoId, index, total })
        );

        const percent = Math.round(((index + 1) / total) * 100);
        setUploadProgress(percent);
      }

      localStorage.removeItem(STATE_KEY);
      setStatus?.('✅ Video upload complete.');
      setUploadComplete(true);
    } catch (err) {
      console.error('[useVideoUpload] Upload error', err);
      setUploadError('❌ Upload error');
      setStatus?.('❌ Upload error');
    }
  };

  return { uploadProgress, uploadComplete, uploadError, startUpload };
}


