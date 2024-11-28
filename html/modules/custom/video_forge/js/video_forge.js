/*(function (Drupal, once) {
  Drupal.behaviors.videoForgeSubtitles = {
    attach: function (context, settings) {
      const videoElements = once('videoForgeSubtitles', 'video', context);
      videoElements.forEach((videoElement) => {
        console.log('Initializing SubtitlesOctopus for video:', videoElement);
        console.log('Subtitles URL:', settings.videoForge.subtitlesUrl);

        // Initialize JavascriptSubtitlesOctopus
        const options = {
          video: videoElement,
          subUrl: settings.videoForge.subtitlesUrl,
          workerUrl: Drupal.url('modules/custom/video_forge/js/libass/package/dist/js/subtitles-octopus-worker.js'),
          wasmUrl: Drupal.url('modules/custom/video_forge/js/libass/package/dist/js/subtitles-octopus-worker.wasm'),
		fonts: [
			'/modules/custom/video_forge/js/libass/package/dist/js/AntonSC-Regular.ttf',
		]
        };

        // Attach subtitles
        new SubtitlesOctopus(options);
      });
    }
  };
})(Drupal, once);
*/
(function (Drupal, once) {
  Drupal.behaviors.videoForgeSubtitles = {
    attach: function (context, settings) {
      const videoElements = once('videoForgeSubtitles', 'video', context);
      videoElements.forEach((videoElement) => {
        // Check if this video is inside the field--name-field-media-video-file container.
        const parentContainer = videoElement.closest('.field--name-field-media-video-file');

        if (parentContainer) {
          console.log('Initializing SubtitlesOctopus for video:', videoElement);
          console.log('Subtitles URL:', settings.videoForge.subtitlesUrl);

          // Initialize JavascriptSubtitlesOctopus
          const options = {
            video: videoElement,
            subUrl: settings.videoForge.subtitlesUrl,
            workerUrl: Drupal.url('modules/custom/video_forge/js/libass/package/dist/js/subtitles-octopus-worker.js'),
            wasmUrl: Drupal.url('modules/custom/video_forge/js/libass/package/dist/js/subtitles-octopus-worker.wasm'),
            fonts: [
              '/modules/custom/video_forge/js/libass/package/dist/js/AntonSC-Regular.ttf',
            ],
          };

          // Attach subtitles
          new SubtitlesOctopus(options);
        } else {
          console.log('Skipping SubtitlesOctopus initialization for video:', videoElement);
        }
      });
    }
  };
})(Drupal, once);

