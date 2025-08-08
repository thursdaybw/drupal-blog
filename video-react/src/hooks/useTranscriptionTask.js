import { useState } from 'react';
import { useAudioTranscription } from './useAudioTranscription';

export function useTranscriptionTask({ setStatus, videoId }) {
  const [taskId, setTaskId] = useState(null);
  const [pollUrl, setPollUrl] = useState(null);
  const [inProgress, setInProgress] = useState(false);
  const [error, setError] = useState(null);

  const { extractAudio, uploadAudio } = useAudioTranscription({ setStatus });

  const startTranscription = async (videoFile) => {
    setInProgress(true);
    setError(null);
    try {
      setStatus?.('🔧 Initializing task...');
      const res = await fetch(`/video-forge/transcription-task-init?video_id=${videoId}`, { credentials: 'include' });
      const json = await res.json();
      const { task_id, poll_url } = json;
      if (!task_id || !poll_url) throw new Error('Task init failed');
      setTaskId(task_id); setPollUrl(poll_url);

      setStatus?.('🎧 Extracting audio…');
      const audioBlob = await extractAudio(videoFile);
      if (!audioBlob) throw new Error('Audio extraction failed');
      setStatus?.('📤 Uploading audio...');
      const ok = await uploadAudio(audioBlob, task_id);
      if (!ok) { setStatus?.('❌ Upload failed — not provisioning'); return; }

      setStatus?.('🚀 Triggering transcription...');
      const provisionRes = await fetch(
        `/video-forge/transcription-provision?task_id=${task_id}`
      );
      const provisionJson = await provisionRes.json();

      console.log('✅ Provisioning response:', provisionJson);
      setStatus?.('🔄 Transcription in progress...');
    } catch (err) {
      console.error('🛑 Transcription task failed:', err);
      setError(err);
      setStatus?.('❌ Failed to start transcription.');
    } finally {
      setInProgress(false);
    }
  };

  return {
    startTranscription,
    taskId,
    pollUrl,
    inProgress,
    error,
  };
}

