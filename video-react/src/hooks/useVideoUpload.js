import { useState } from 'react';

export function useVideoUpload({ setStatus }) {
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadComplete, setUploadComplete] = useState(false);
  const [uploadError, setUploadError] = useState(null);

  const startUpload = async (videoFile, videoId, taskId) => {
    console.log('[useVideoUpload] startUpload called');
    console.log('File:', videoFile?.name, videoFile?.size, videoFile?.type);
    console.log('videoId:', videoId, 'taskId:', taskId);

    if (!videoFile || !videoId) {
      console.warn('[useVideoUpload] Missing file or videoId');
      return;
    }

    setStatus?.('📤 Uploading video…');
    setUploadProgress(0);
    setUploadComplete(false);
    setUploadError(null);

    try {
      // const chunkSize = 5 * 1024 * 1024; // 5MB
      const chunkSize = 1 * 1024 * 1024; // 1MB
      const uploadId = (crypto.randomUUID?.() || String(Date.now()));
      const total = Math.ceil(videoFile.size / chunkSize);
      console.log('[useVideoUpload] Using chunked upload. totalChunks=', total, 'uploadId=', uploadId);

      console.log('[useVideoUpload] file.size=', videoFile.size, 'lastModified=', videoFile.lastModified);
      const _initialSize = videoFile.size;
      const _initialLastModified = videoFile.lastModified;

      for (let index = 0; index < total; index++) {

        if (videoFile.size !== _initialSize || videoFile.lastModified !== _initialLastModified) {
          console.error('[useVideoUpload] Source file metadata changed during upload', {
            initialSize: _initialSize, currentSize: videoFile.size,
            initialLastModified: _initialLastModified, currentLastModified: videoFile.lastModified,
            index, total
          });
        }

        const start = index * chunkSize;
        const end = Math.min(start + chunkSize, videoFile.size);
        const blob = videoFile.slice(start, end);

        console.log(`[useVideoUpload] Preparing chunk ${index + 1}/${total}, bytes ${start}-${end}`);

        const formData = new FormData();
        formData.append('file', blob, `part-${index}.bin`);

        let uploadUrl = `/video-forge/upload-video?video_id=${videoId}&upload_id=${uploadId}&index=${index}&total=${total}`;
        if (taskId) uploadUrl += `&task_id=${taskId}`;

        console.log('[useVideoUpload] Sending chunk to', uploadUrl);

        const res = await fetch(uploadUrl, { method: 'POST', body: formData, credentials: 'include' });

        if (!res.ok) {
          const msg = `❌ Upload failed on chunk ${index + 1}/${total} with status ${res.status}`;
          console.error('[useVideoUpload]', msg);
          setUploadError(msg);
          setStatus?.(msg);
          return;
        }

        const percent = Math.round(((index + 1) / total) * 100);
        console.log('[useVideoUpload] Progress:', percent, '%');
        setUploadProgress(percent);
      }

      setStatus?.('✅ Video upload complete.');
      setUploadComplete(true);
    } catch (err) {
      console.error('[useVideoUpload] Upload error', err);
      const msg = '❌ Upload error';
      setUploadError(msg);
      setStatus?.(msg);
    }
  };

  return {
    uploadProgress,
    uploadComplete,
    uploadError,
    startUpload,
  };
}

