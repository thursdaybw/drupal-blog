import { useRef } from 'react';
import { FFmpeg } from '@ffmpeg/ffmpeg';
import { fetchFile } from '@ffmpeg/util';

export function useAudioTranscription({ setStatus }) {
  const ffmpegRef = useRef(
    new FFmpeg({
      log: true,
      corePath: '/video-react/ffmpeg-core/ffmpeg-core.js', // adjust if needed
    })
  );

  const ffmpeg = ffmpegRef.current;

  ffmpeg.on('log', ({ message }) => {
    console.log('[ffmpeg]', message);
  });

  const extractAudio = async (file) => {
    try {
      setStatus?.('Loading FFmpeg…');
      await ffmpeg.load();

      const inputData = await fetchFile(file);
      await ffmpeg.writeFile('in.mp4', inputData);
      await ffmpeg.exec(['-i', 'in.mp4', '-q:a', '0', '-map', 'a', 'out.mp3']);

      const outData = await ffmpeg.readFile('out.mp3');
      if (!outData?.length) throw new Error('No audio extracted');

      const blob = new Blob([outData.buffer], { type: 'audio/mpeg' });
      setStatus?.('Audio extracted!');
      return blob;
    } catch (err) {
      console.error('🛑 extractAudio error:', err);
      setStatus?.('Error during audio extraction.');
      return null;
    }
  };

  const uploadAudio = async (blob, task_id) => {
    if (!blob || !task_id) return;

    setStatus?.('Uploading audio…');

    try {
      const formData = new FormData();
      formData.append('file', blob, 'audio.mp3');

      const res = await fetch(`/video-forge/upload-audio?task_id=${task_id}`, {
        method: 'POST',
        body: formData,
        credentials: 'include',
      });

      if (!res.ok) throw new Error(`Upload failed: ${res.status}`);
      setStatus?.('Upload complete!');
    } catch (err) {
      console.error('🛑 uploadAudio error:', err);
      setStatus?.('Upload failed.');
    }
  };

  return { extractAudio, uploadAudio };
}

