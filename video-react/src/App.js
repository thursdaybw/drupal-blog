import React, { useRef, useState } from 'react';
import { FFmpeg }    from '@ffmpeg/ffmpeg';
import { fetchFile } from '@ffmpeg/util';

// 👇 Add this just below imports
const base = window.location.pathname.replace(/\/$/, '');

function App() {
  const [status, setStatus]     = useState('Idle');
  const [audioURL, setAudioURL] = useState(null);
  const [videoURL, setVideoURL] = useState(null)
  const ffmpegRef = useRef(
    new FFmpeg({
      log: true,
      corePath: `${base}/ffmpeg-core/ffmpeg-core.js`, // ✅ use base
    })
  );

  const ffmpeg = ffmpegRef.current;

    ffmpeg.on('log', ({ message }) => {
        console.log('[ffmpeg]', message);
    });

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

    const link = document.createElement('a');
    link.href     = url;
    link.download = 'extracted.mp3';
    link.textContent = 'Download MP3';
    link.style.display = 'block';
    document.body.appendChild(link);

  } catch (err) {
    console.error('🔥 extractAudio() threw:', err);
    setStatus('Error during extraction');
  }
};



  return (
    <div style={{ padding: '2rem' }}>
      <h1>FFmpeg.wasm React Demo</h1>
      <input
        type="file"
        accept="video/*"
        onChange={e => extractAudio(e.target.files[0])}
      />
      <p>Status: {status}</p>
      {videoURL && (
          <video
          controls
          src={videoURL}
          width="480"
          style={{ marginTop: '1rem', display: 'block' }}
          />
      )}
      {audioURL && <audio controls src={audioURL} style={{ marginTop:'1rem' }} />}
    </div>
  );
}

export default App;

