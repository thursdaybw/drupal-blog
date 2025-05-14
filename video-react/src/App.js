import React, { useRef, useState, useEffect, useCallback } from 'react';
import { FFmpeg }    from '@ffmpeg/ffmpeg';
import { fetchFile } from '@ffmpeg/util';

const base = window.location.pathname.replace(/\/$/, '');

function App() {
  const [status, setStatus]         = useState('Idle');
  const [taskId, setTaskId]         = useState(null);
  const [pollUrl, setPollUrl]       = useState(null);
  const [audioURL, setAudioURL]     = useState(null);
  const [videoURL, setVideoURL]     = useState(null)
  const [videoFile, setVideoFile]   = useState(null);
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

  // Polling Loop A: provisioning status poll
  useEffect(() => {
    if (!pollUrl) return;

    let shouldContinue = true;

    const pollProvisionStatus = async () => {
      try {
        const res = await fetch(pollUrl);
        const json = await res.json();

        if (json.status === 'ready') {
          setStatus('Server ready – uploading audio...');
          shouldContinue = false;
          uploadAudio();
        } else if (json.status === 'transcribed') {
          setStatus('✅ Transcription complete! Captions ready.');
          setAssUrl(json.ass_url || null);
          shouldContinue = false;
        } else {
          console.log('Polling… status =', json.status);
          setStatus(`Waiting for server… (${json.status})`);
        }
      } catch (err) {
        console.warn('Provisioning polling failed:', err);
      }

      if (shouldContinue) {
        setTimeout(pollProvisionStatus, 3000);
      }
    };

    pollProvisionStatus();

    return () => {
      shouldContinue = false;
    };
  }, [pollUrl]);

  // Polling Loop B: transcription status poll
  useEffect(() => {
    if (!taskId || !audioURL) return;

    let shouldContinue = true;

    const poll = async () => {
      try {
        const res = await fetch(`/video-forge/transcription-provision-status?task_id=${taskId}`);
        const json = await res.json();

        if (json.status === 'transcribed') {
          setStatus('✅ Transcription complete! Captions ready.');
          setAssUrl(json.ass_url || null);
          shouldContinue = false;
        } else {
          console.log('Polling transcription… status =', json.status);
          setStatus(`Transcription in progress… (${json.status})`);
        }
      } catch (err) {
        console.warn('Transcription polling failed:', err);
      }

      if (shouldContinue) {
        setTimeout(poll, 3000);
      }
    };

    poll(); // Start polling

    return () => {
      shouldContinue = false;
    };
  }, [taskId, audioURL]);


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

  const provisionTranscription = async () => {
    try {
      const res = await fetch('/video-forge/transcription-provision');
      const json = await res.json();

      console.log('Provisioning response:', json);
      setPollUrl(json.poll_url);
      setTaskId(json.task_id);
      setStatus('Provisioning server...');
    } catch (err) {
      console.error('Provisioning failed:', err);
      setStatus('Failed to provision server.');
    }
  };

  const extractAudio = async (file) => {
    try {
      // === Step A: start ===
      console.log('⏳ extractAudio(): start');
      console.log('Selected file:', file.name, file.type, file.size, 'bytes');

      const fileURL = URL.createObjectURL(file); // ✅ preview video
      setVideoURL(fileURL);

      // === Step B: load core files ===
      setStatus('Loading FFmpeg…');
      await ffmpeg.load(); // ✅ uses corePath from constructor
      console.log('✅ core files loaded');

      // === Step C: read file from <input> ===
      const inputData = await fetchFile(file);
      console.log('🏊 fetchFile size:', inputData.length);

      // === Step D: write to FS and confirm ===
      await ffmpeg.writeFile('in.mp4', inputData);
      const confirmIn = await ffmpeg.readFile('in.mp4');
      console.log('📂 in.mp4 in FS size:', confirmIn.length);

      // === Step E: run extraction ===
      setStatus('Extracting audio…');
      await ffmpeg.exec([
        '-i', 'in.mp4',
        '-q:a', '0',
        '-map', 'a',
        'out.mp3',
      ]);
      console.log('✅ ffmpeg.exec completed');

      const files = await ffmpeg?.fs?.readdir?.('/');
      console.log('📁 FS contents:', files);

      // === Step F: read back output ===
      const outData = await ffmpeg.readFile('out.mp3');
      console.log('🔊 out.mp3 in FS size:', outData.length);

      if (outData.length === 0) {
        console.warn('⚠️ out.mp3 came back empty — no audio was extracted.');
        setStatus('Error: no audio extracted.');
        return;
      }

      // === Step G: build blob / player / link ===
      const blob = new Blob([outData.buffer], { type: 'audio/mpeg' });
      const url  = URL.createObjectURL(blob);
      console.log('🌐 Blob URL:', url);

      setAudioURL(url);
      setStatus('Done!');

    } catch (err) {
      console.error('🔥 extractAudio() threw:', err);
      setStatus('Error during extraction');
    }
  };

  const uploadAudio = useCallback(async () => {
    if (!audioURL) {
      alert('No audio to upload!');
      return;
    }

    setStatus('Uploading audio to transcription server…');

    try {
      const response = await fetch(audioURL);

      const audioBlob = await response.blob();

      const formData = new FormData();
      formData.append('file', audioBlob, 'audio.mp3');

      const uploadRes = await fetch(`/video-forge/upload-audio?task_id=${taskId}`, {
        method: 'POST',
        body: formData,
        credentials: 'include',
      });

      if (!uploadRes.ok) {
        throw new Error(`Upload failed with status ${uploadRes.status}`);
      }

      console.log('✅ Audio upload complete.');
      setStatus('Upload complete! Transcription in progress...');

    } catch (err) {
      console.error('Upload failed:', err);
      setStatus('Upload failed.');
    }
  }, [audioURL, taskId]);

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
        await extractAudio(videoFile);
        const res = await fetch('/video-forge/transcription-provision');
        const json = await res.json();
        console.log('Provisioning response:', json);

        setPollUrl(json.poll_url);
        setTaskId(json.task_id);
        setStatus('Provisioning server...');
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

