import { useEffect } from 'react';

export function usePollTaskStatus({ pollUrl, onStatus, onComplete, enabled = true }) {
  useEffect(() => {
    if (!enabled || !pollUrl) return;

    let shouldContinue = true;

    const poll = async () => {
      try {
        const res = await fetch(pollUrl);
        const json = await res.json();
        const { status, meta = {} } = json;

        if (status === 'transcribed') {
          onStatus?.('✅ Transcription complete!');
          onComplete?.({ assUrl: meta.ass_url || null });
          shouldContinue = false;
        } else {
          console.log('[poll]', status);
          onStatus?.(`Waiting for server… (${status})`);
        }
      } catch (err) {
        console.warn('[poll] failed:', err);
        onStatus?.('⚠️ Polling failed');
      }

      if (shouldContinue) {
        setTimeout(poll, 3000);
      }
    };

    poll();

    return () => {
      shouldContinue = false;
    };
  }, [pollUrl, enabled, onStatus, onComplete]);
}

