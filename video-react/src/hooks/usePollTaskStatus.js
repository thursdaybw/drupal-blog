import { useEffect } from 'react';

export function usePollTaskStatus({ pollUrl, setStatus, onComplete, enabled = true }) {
  useEffect(() => {
    if (!enabled || !pollUrl) return;

    let shouldContinue = true;

    const poll = async () => {
      try {
        const res = await fetch(pollUrl);
        const json = await res.json();
        const { status, meta = {}, error_message } = json;

        if (status === 'transcribed') {
          setStatus?.('✅ Transcription complete!');
          onComplete?.({
            assUrl: meta.ass_url || null,
            renderUrl: meta.render_url || null,
          });
          shouldContinue = false;

        } else if (status === 'error') {
          setStatus?.(`❌ Server error: ${error_message || 'Unknown error'}`);
          shouldContinue = false;

        } else if (status === 'render_complete' && meta.render_url) {
          setStatus?.('✅ Render complete!');
          onComplete?.({
            assUrl: meta.ass_url || null,
            renderUrl: meta.render_url || null,
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

  useEffect(() => {
    const resume = () => {
      shouldContinue = true;
      poll(); // re-run polling
    };
    window.addEventListener('resume-poll', resume);
    return () => window.removeEventListener('resume-poll', resume);
  }, []);

}

