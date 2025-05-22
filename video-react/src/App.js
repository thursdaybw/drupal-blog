import React, { useRef, useState, useEffect, useCallback } from 'react';
import { FFmpeg }    from '@ffmpeg/ffmpeg';
import { fetchFile } from '@ffmpeg/util';
import { useAudioTranscription } from './hooks/useAudioTranscription'; // adjust path
import { usePollTaskStatus } from './hooks/usePollTaskStatus';
import { useSubtitleOverlay } from './hooks/useSubtitleOverlay';
import { useTranscriptionTask } from './hooks/useTranscriptionTask';
import { useVideoUpload } from './hooks/useVideoUpload';
import { useRenderVideo } from './hooks/useRenderVideo';

const base = window.location.pathname.replace(/\/$/, '');

function App() {
  const [status, setStatus]               = useState('Idle');
  const [audioURL, setAudioURL]           = useState(null);
  const [videoURL, setVideoURL]           = useState(null)
  const [videoFile, setVideoFile]         = useState(null);
  const [videoId, setVideoId]             = useState(null);
  const [renderUrl, setRenderUrl]         = useState(null);
  const [transcriptUrl, setTranscriptUrl] = useState(null);
  const [transcriptText, setTranscriptText] = useState('');

  const { extractAudio, uploadAudio } = useAudioTranscription({
    setStatus: setStatus
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
    setStatus: setStatus,
  });

  const { triggerRender, rendering } = useRenderVideo({
    taskId,
    onStatus: setStatus,
  });

  const {
    uploadProgress,
    uploadComplete,
    uploadError,
    startUpload
  } = useVideoUpload({ setStatus: setStatus });

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

  const fileRef = useRef(null);

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
    setStatus: setStatus,
    onComplete: ({ assUrl, renderUrl, transcriptUrl }) => {
      setAssUrl(assUrl);
      setRenderUrl(renderUrl);
      setTranscriptUrl(transcriptUrl); // <-- same pattern
    },
    enabled: Boolean(pollUrl),
  });

  useEffect(() => {
    if (!transcriptUrl) return;

    const fetchTranscript = async () => {
      try {
        const res = await fetch(transcriptUrl);
        const json = await res.json();
        setTranscriptText(json.text || '');
      } catch (err) {
        console.warn('❌ Failed to fetch transcript JSON:', err);
      }
    };

    fetchTranscript();
  }, [transcriptUrl]);

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
        const video_id = crypto.randomUUID();
        fileRef.current = file;

        // 🧠 Previously we used URL.createObjectURL(file) to generate a blob URL for video playback:
        // const url = URL.createObjectURL(file);
        // setVideoURL(url);
        //
        // This works well in most environments, but on mobile (especially Chrome on Android),
        // it leads to instability when used alongside SubtitlesOctopus.
        //
        // SubtitlesOctopus loads the video into a Web Worker for subtitle rendering,
        // which tries to access the same blob URL as the <video> element.
        // On memory-constrained devices or under heavy load, this dual access causes
        // Chrome to revoke or invalidate the blob mid-stream, triggering:
        //   net::ERR_UPLOAD_FILE_CHANGED
        //
        // 🎯 To solve this, we now use FileReader to convert the video file to a base64-encoded
        // data URI (data:video/mp4;base64,...). This ensures both the video element and
        // SubtitlesOctopus can safely access the same source without memory collisions.
        //
        // 🔻 Drawbacks of this approach:
        // - Base64 encoding increases memory usage (~33% larger than the original file)
        // - Longer load time for large videos due to encoding delay
        // - No revocation possible (data URIs persist for the page lifecycle)
        //
        // ✅ However, it's far more stable for mobile testing and avoids the playback crash.
        //
        // 🔄 If we switch away from SubtitlesOctopus (e.g. use native <track> subtitles,
        // or handle caption rendering manually), we can safely revert to:
        //   const url = URL.createObjectURL(file);
        //   setVideoURL(url);

        const reader = new FileReader();
        reader.onload = () => {
          setVideoFile(file);
          setVideoId(video_id);
          setVideoURL(reader.result); // Base64 data URI (e.g. data:video/mp4;base64,...)
          startUpload(file, video_id);
        };
        reader.readAsDataURL(file);
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
    {videoFile && (
      <button
      onClick={triggerRender}
      disabled={!taskId || !assUrl || rendering}
      style={{
        marginTop: '1rem',
          backgroundColor: rendering ? '#ccc' : '#4CAF50',
          color: '#fff',
          padding: '0.5rem 1rem',
          border: 'none',
          cursor: rendering ? 'not-allowed' : 'pointer',
      }}
      >
      {rendering ? 'Rendering…' : 'Render Final Video'}
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

    {renderUrl && (
      <a
      href={renderUrl}
      download
      style={{
        display: 'inline-block',
          marginTop: '1rem',
          padding: '0.5rem 1rem',
          backgroundColor: '#2196F3',
          color: '#fff',
          textDecoration: 'none',
      }}
      >
      ⬇️ Download Rendered Video
      </a>
    )}

    {uploadProgress > 0 && (
      <p>Upload Progress: {uploadProgress}%</p>
    )}

    {transcriptText && (
      <textarea
      value={transcriptText}
      readOnly
      rows={10}
      style={{ width: '100%', marginTop: '1rem' }}
      />
    )}


    </div>
  );
}

export default App;

