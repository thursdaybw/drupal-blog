import { useEffect } from 'react';

export function usePollTaskStatus({ pollUrl, setStatus, onComplete, enabled = true }) {

  useEffect(() => {

    if (!enabled || !pollUrl) return;

    let shouldContinue = true;

    const poll = async () => {
      try {
        const res = await fetch(pollUrl);
        const json = await res.json();
        const { status, transcript_ready, transcript_url, ass_url, render_url, error_message } = json;

        if (transcript_ready && transcript_url) {
          setStatus?.('✅ Transcription complete!');
          onComplete?.({
            assUrl: ass_url || null,
            renderUrl: render_url || null,
            transcriptUrl: transcript_url || null,
          });
        } else if (status === 'error') {
          setStatus?.(`❌ Server error: ${error_message || 'Unknown error'}`);
          shouldContinue = false;
        } else if (status === 'render_complete' && render_url) {
          setStatus?.('✅ Render complete!');
          onComplete?.({
            assUrl: ass_url || null,
            renderUrl: render_url || null,
          });
          shouldContinue = false;
        } else {
          console.log('[poll]', status);
          setStatus?.(`Waiting for server… (${status})`);
        }

      } catch (err) {
        console.warn('[poll] failed:', err);
        setStatus?.('⚠️  Polling failed');
      }

      if (shouldContinue) {
        setTimeout(poll, 3000);
      }
    };

    poll();

    return () => {
      shouldContinue = false;
    };
  }, [pollUrl, enabled, setStatus, onComplete]);
}

