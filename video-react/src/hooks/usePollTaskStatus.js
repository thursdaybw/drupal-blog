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

        switch (status) {
          case 'error':
            setStatus?.(`❌ Server error: ${error_message || 'Unknown error'} (status: ${status})`);
            shouldContinue = false;
            break;

          case 'rendering':
            setStatus?.(`Rendering in progress… (status: ${status})`);
            break;

          case 'render_complete':
            if (render_url) {
              setStatus?.(`✅ Render complete! (status: ${status})`);
              onComplete?.({
                assUrl: ass_url || null,
                renderUrl: render_url || null,
                transcriptUrl: transcript_url || null,
              });
              shouldContinue = false;
            }
            break;

          default:
            if (transcript_ready && transcript_url) {
              setStatus?.(`✅ Transcription complete! (status: ${status})`);
              onComplete?.({
                assUrl: ass_url || null,
                renderUrl: render_url || null,
                transcriptUrl: transcript_url || null,
              });
            } else {
              console.log('[poll]', status);
              setStatus?.(`Waiting for server… (status: ${status})`);
            }
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

