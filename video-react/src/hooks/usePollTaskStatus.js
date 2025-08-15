// usePollTaskStatus.js
import { useEffect, useRef } from 'react';

export function usePollTaskStatus({ pollUrl, setStatus, onComplete, enabled = true }) {
  const errorLocked = useRef(false);

  useEffect(() => {
    errorLocked.current = false;
    if (!enabled || !pollUrl) return;

    let shouldContinue = true;
    let timeoutId;

    const poll = async () => {
      try {
        const res = await fetch(pollUrl);
        const json = await res.json();
        const { status, transcript_ready, transcript_text, ass_url, render_url, error_message } = json;

        switch (status) {
          case 'error':
            errorLocked.current = true;
            setStatus?.(`❌ Server error: ${error_message || 'Unknown error'} (status: ${status})`);
            shouldContinue = false;
            break;

          case 'rendering':
            if (!errorLocked.current) setStatus?.(`Rendering in progress… (status: ${status})`);
            break;

          case 'render_complete':
            if (render_url) {
              if (!errorLocked.current) setStatus?.(`✅ Render complete! (status: ${status})`);
              onComplete?.({
                assUrl: ass_url || null,
                renderUrl: render_url || null,
                transcriptText: transcript_text|| null,
              });
              shouldContinue = false;
            }
            break;

          default:
            if (transcript_ready && transcript_text) {
              if (!errorLocked.current) setStatus?.(`✅ Transcription complete! (status: ${status})`);
              onComplete?.({
                assUrl: ass_url || null,
                renderUrl: render_url || null,
                transcriptText: transcript_text || null,
              });
            } else {
              if (!errorLocked.current) {
                console.log('[poll]', status);
                setStatus?.(`Waiting for server… (status: ${status})`);
              }
            }
        }
      } catch (err) {
        console.warn('[poll] failed:', err);
        if (!errorLocked.current) setStatus?.('⚠️ Polling failed');
      }

      if (shouldContinue) timeoutId = setTimeout(poll, 3000);
    };

    const onVisibilityChange = () => {
      if (!document.hidden && shouldContinue) poll(); // snap back on wake
    };
    document.addEventListener('visibilitychange', onVisibilityChange);

    poll();

    return () => {
      shouldContinue = false;
      clearTimeout(timeoutId);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [pollUrl, enabled, setStatus, onComplete]);
}

