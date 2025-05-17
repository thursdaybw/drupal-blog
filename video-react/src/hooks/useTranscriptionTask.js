import { useState } from 'react';
import { useAudioTranscription } from './useAudioTranscription';

export function useTranscriptionTask({ onStatus }) {
  const [taskId, setTaskId] = useState(null);
  const [pollUrl, setPollUrl] = useState(null);
  const [inProgress, setInProgress] = useState(false);
  const [error, setError] = useState(null);

  const { extractAudio, uploadAudio } = useAudioTranscription({ onStatus });

  const startTranscription = async (videoFile) => {
    setInProgress(true);
    setError(null);

    try {
      onStatus?.('🔧 Initializing task...');
      const res = await fetch('/video-forge/transcription-task-init', {
        credentials: 'include',
      });
      const json = await res.json();
      const { task_id, poll_url } = json;

      if (!task_id || !poll_url) {
        throw new Error('Task init failed');
      }

      setTaskId(task_id);
      setPollUrl(poll_url);

      onStatus?.('🎧 Extracting audio…');
      const audioBlob = await extractAudio(videoFile);

      onStatus?.('📤 Uploading audio...');
      await uploadAudio(audioBlob, task_id);

      onStatus?.('🚀 Triggering transcription...');
      const provisionRes = await fetch(
        `/video-forge/transcription-provision?task_id=${task_id}`
      );
      const provisionJson = await provisionRes.json();

      console.log('✅ Provisioning response:', provisionJson);
      onStatus?.('🔄 Transcription in progress...');
    } catch (err) {
      console.error('🛑 Transcription task failed:', err);
      setError(err);
      onStatus?.('❌ Failed to start transcription.');
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

