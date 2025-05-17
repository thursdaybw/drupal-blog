import React, { useRef, useState, useEffect, useCallback } from 'react';
import { FFmpeg }    from '@ffmpeg/ffmpeg';
import { fetchFile } from '@ffmpeg/util';
import { useAudioTranscription } from './hooks/useAudioTranscription'; // adjust path
import { usePollTaskStatus } from './hooks/usePollTaskStatus';
import { useSubtitleOverlay } from './hooks/useSubtitleOverlay';
import { useTranscriptionTask } from './hooks/useTranscriptionTask';
import { useVideoUpload } from './hooks/useVideoUpload';


const base = window.location.pathname.replace(/\/$/, '');

function App() {
  const [status, setStatus]       = useState('Idle');
  const [audioURL, setAudioURL]   = useState(null);
  const [videoURL, setVideoURL]   = useState(null)
  const [videoFile, setVideoFile] = useState(null);
  const [videoId, setVideoId]     = useState(null);

  const { extractAudio, uploadAudio } = useAudioTranscription({
    onStatus: setStatus
  });

  const {
    startTranscription,
    pollUrl,
    taskId,
    inProgress,
    error,
  } = useTranscriptionTask({
    videoFile,
    videoId,
    onStatus: setStatus,
  });

  const {
    uploadProgress,
    uploadComplete,
    uploadError,
    startUpload
  } = useVideoUpload({ onStatus: setStatus });

  const [assUrl, setAssUrl]         = useState(null);

  // TODO: Replace hardcoded modulePath with a dynamic lookup from Drupal (e.g. via JSON:API or injected config)
  const modulePath = '/modules/contrib/video_forge';

  const videoRef = useRef(null);

  const ffmpegRef = useRef(
    new FFmpeg({
      log: true,
      corePath: `${base}/ffmpeg-core/ffmpeg-core.js`,
    })
  );

  const ffmpeg = ffmpegRef.current;

  ffmpeg.on('log', ({ message }) => {
    console.log('[ffmpeg]', message);
  });

  const checkDrupalUser = async () => {
    try {
      const res = await fetch('/jsonapi/user/user?filter[uid][value]=1', {
        credentials: 'include',
        headers: {
          'Accept': 'application/vnd.api+json'
        }
      });

      const json = await res.json();
      const user = json.data?.[0]?.attributes;
      console.log('✅ JSON:API user:', user);
      setStatus(`Logged in as: ${user.display_name}`);
    } catch (err) {
      console.warn('⚠️ Could not fetch user via JSON:API:', err);
      setStatus('Anonymous or error');
    }
  };

  useEffect(() => {
    checkDrupalUser();
  }, []);

  usePollTaskStatus({
    pollUrl,
    onStatus: setStatus,
    onComplete: ({ assUrl }) => setAssUrl(assUrl),
    enabled: Boolean(pollUrl),
  });

  useSubtitleOverlay({
    assUrl,
    videoRef,
    modulePath,
  });

  const provisionTranscription = async (task_id) => {
    const res = await fetch(`/video-forge/transcription-provision?task_id=${task_id}`);
    const json = await res.json();

    console.log('Provisioning response:', json);
    setStatus('Provisioning server...');
  };

  return (
    <div style={{ padding: '2rem' }}>
    <h1>FFmpeg.wasm React Demo</h1>

    <input
    type="file"
    accept="video/*"
    onChange={e => {
      const file = e.target.files[0];
      if (file) {
        const video_id = crypto.randomUUID(); // 🆕
        setVideoFile(file);
        setVideoId(video_id); // 🆕
        setVideoURL(URL.createObjectURL(file));
        startUpload(file, video_id); // pass to uploader
      }
    }}
    />

    {videoFile && (
      <button
      onClick={() => startTranscription(videoFile)}
      style={{ marginTop: '1rem' }}
      >
      Generate Captions
      </button>
    )}

    <p>Status: {status}</p>
    {videoURL && (
      <video
      ref={videoRef}
      controls
      src={videoURL}
      width="480"
      style={{ marginTop: '1rem', display: 'block' }}
      />
    )}

    {uploadProgress > 0 && (
      <p>Upload Progress: {uploadProgress}%</p>
    )}

    </div>
  );
}

export default App;

