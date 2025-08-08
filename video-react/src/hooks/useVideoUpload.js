import { useState } from 'react';

export function useVideoUpload({ setStatus }) {
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadComplete, setUploadComplete] = useState(false);
  const [uploadError, setUploadError] = useState(null);

  const startUpload = (videoFile, videoId, taskId) => {
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

    const xhr = new XMLHttpRequest();

    const uploadUrl = `/video-forge/upload-video?video_id=${videoId}` + (taskId ? `&task_id=${taskId}` : '');
    console.log('[useVideoUpload] Opening POST to', uploadUrl);
    xhr.open('POST', uploadUrl, true);
    xhr.withCredentials = true;

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        console.log('[useVideoUpload] Progress:', e.loaded, '/', e.total, `(${percent}%)`);
        setUploadProgress(percent);
      } else {
        console.log('[useVideoUpload] Progress event: length not computable');
      }
    };

    xhr.onload = () => {
      console.log('[useVideoUpload] onload fired. Status:', xhr.status);
      if (xhr.status >= 200 && xhr.status < 300) {
        setStatus?.('✅ Video upload complete.');
        setUploadComplete(true);
      } else {
        const msg = `❌ Upload failed with status ${xhr.status}`;
        setUploadError(msg);
        setStatus?.(msg);
      }
    };

    xhr.onerror = () => {
      console.error('[useVideoUpload] onerror fired');
      const msg = '❌ Upload error';
      setUploadError(msg);
      setStatus?.(msg);
    };

    xhr.onabort = () => {
      console.warn('[useVideoUpload] onabort fired');
    };

    xhr.ontimeout = () => {
      console.error('[useVideoUpload] ontimeout fired');
    };

    const formData = new FormData();
    console.log('[useVideoUpload] Appending file to FormData');
    formData.append('file', videoFile, videoFile.name);

    console.log('[useVideoUpload] Sending XHR');
    xhr.send(formData);
  };

  return {
    uploadProgress,
    uploadComplete,
    uploadError,
    startUpload,
  };
}

