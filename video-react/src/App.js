import React, { useRef, useState, useEffect, useCallback } from 'react';
import { FFmpeg }    from '@ffmpeg/ffmpeg';
import { fetchFile } from '@ffmpeg/util';
import { useAudioTranscription } from './hooks/useAudioTranscription'; // adjust path
import { usePollTaskStatus } from './hooks/usePollTaskStatus';

const base = window.location.pathname.replace(/\/$/, '');

function App() {
  const [status, setStatus]         = useState('Idle');
  const [pollUrl, setPollUrl]       = useState(null);
  const [audioURL, setAudioURL]     = useState(null);
  const [videoURL, setVideoURL]     = useState(null)
  const [videoFile, setVideoFile]   = useState(null);

  const { extractAudio, uploadAudio } = useAudioTranscription({
    onStatus: setStatus
  });
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

  useEffect(() => {
    if (!assUrl || !videoRef.current) return;

    console.log('📦 Received ASS file URL:', assUrl);
    alert('📝 ASS file ready! SubtitlesOctopus setup is currently disabled.');

    // TODO: Uncomment this once ready to test SubtitlesOctopus
  const script = document.createElement('script');
  script.src = `${modulePath}/js/libass/package/dist/js/subtitles-octopus.js`;

  script.onload = () => {
    const options = {
      video: videoRef.current,
      subUrl: assUrl,
      workerUrl: `${modulePath}/js/libass/package/dist/js/subtitles-octopus-worker.js`,
      wasmUrl: `${modulePath}/js/libass/package/dist/js/subtitles-octopus-worker.wasm`,
      fonts: [
        `${modulePath}/js/libass/package/dist/js/AntonSC-Regular.ttf`
      ],
    };

    // eslint-disable-next-line no-undef
    new SubtitlesOctopus(options);
  };

  document.body.appendChild(script);

  return () => {
    document.body.removeChild(script);
  };

  }, [assUrl]);

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
        setVideoFile(file);
        const fileURL = URL.createObjectURL(file);
        setVideoURL(fileURL);
      }
    }}
    />
    {videoFile && (
      <button
      onClick={async () => {
        // Step 1: Init task and get task ID and poll URL
        const res = await fetch('/video-forge/transcription-task-init', { credentials: 'include' });
        const json = await res.json();
        const { task_id, poll_url } = json;

        if (!task_id || !poll_url) {
          console.log('Task Init:', 'Failed to initialize task');
          setStatus('❌ Failed to initialize task');
          return;
        }
        else {
          console.log('Task Init: Task initialized with id', task_id);
        }

        setPollUrl(poll_url);

        // Step 2: Extract, upload, provision
        const audioBlob = await extractAudio(videoFile);
        // TODO work on doing these concurrently.
        await uploadAudio(audioBlob, task_id);
        await provisionTranscription(task_id);
      }}
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
    {/*audioURL && <audio controls src={audioURL} style={{ marginTop:'1rem' }} />*/}
    </div>
  );
}

export default App;

