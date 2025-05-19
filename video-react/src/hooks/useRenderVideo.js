import { useState } from 'react';

export function useRenderVideo({ taskId, setStatus }) {
  const [rendering, setRendering] = useState(false);
  const [renderError, setRenderError] = useState(null);
  const [renderSuccess, setRenderSuccess] = useState(false);

  const triggerRender = async () => {
    if (!taskId) return;

    setRendering(true);
    setRenderError(null);
    setStatus?.('Queuing render job...');

    try {
      const res = await fetch(`/video-forge/render-task/${taskId}`, {
        credentials: 'include',
      });
      const json = await res.json();

      if (json.error) {
        setRenderError(json.error);
        setStatus?.(`⚠️  Render failed: ${json.error}`);
      } else {
        setRenderSuccess(true);
        setStatus?.('🎬 Render job enqueued');

        // ✅ Only resume polling if render was successfully enqueued
        setTimeout(() => {
          window.dispatchEvent(new Event('resume-poll'));
        }, 100);
      }
    } catch (err) {
      setRenderError(err.message);
      setStatus?.(`⚠️  Render error: ${err.message}`);
    }

    setRendering(false);
  };

  return {
    triggerRender,
    rendering,
    renderError,
    renderSuccess,
  };
}

