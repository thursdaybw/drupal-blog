import { useState } from 'react';

export function useVideoUpload({ setStatus }) {
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadComplete, setUploadComplete] = useState(false);
  const [uploadError, setUploadError] = useState(null);

const startUpload = (videoFile, videoId) => {
  if (!videoFile || !videoId) return;

    setStatus?.('📤 Uploading video…');
    setUploadProgress(0);
    setUploadComplete(false);
    setUploadError(null);

    const xhr = new XMLHttpRequest();

    xhr.open('POST', `/video-forge/upload-video?video_id=${videoId}`, true);

    xhr.withCredentials = true;

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        setUploadProgress(percent);
      }
    };

    xhr.onload = () => {
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
      const msg = '❌ Upload error';
      setUploadError(msg);
      setStatus?.(msg);
    };

    const formData = new FormData();
    formData.append('file', videoFile, videoFile.name);

    xhr.send(formData);
  };

  return {
    uploadProgress,
    uploadComplete,
    uploadError,
    startUpload,
  };
}

