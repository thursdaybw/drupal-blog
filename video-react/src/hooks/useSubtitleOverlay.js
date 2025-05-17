import { useEffect } from 'react';

export function useSubtitleOverlay({ assUrl, videoRef, modulePath }) {
  useEffect(() => {
    if (!assUrl || !videoRef?.current) return;

    console.log('📦 Setting up SubtitlesOctopus:', assUrl);

    const script = document.createElement('script');
    script.src = `${modulePath}/js/libass/package/dist/js/subtitles-octopus.js`;

    script.onload = () => {
      const options = {
        video: videoRef.current,
        subUrl: assUrl,
        workerUrl: `${modulePath}/js/libass/package/dist/js/subtitles-octopus-worker.js`,
        wasmUrl: `${modulePath}/js/libass/package/dist/js/subtitles-octopus-worker.wasm`,
        fonts: [`${modulePath}/js/libass/package/dist/js/AntonSC-Regular.ttf`],
      };

      // eslint-disable-next-line no-undef
      new SubtitlesOctopus(options);
    };

    document.body.appendChild(script);

    return () => {
      document.body.removeChild(script);
    };
  }, [assUrl, videoRef, modulePath]);
}

