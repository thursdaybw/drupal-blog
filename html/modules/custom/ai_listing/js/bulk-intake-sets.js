(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.aiListingBulkIntakeSets = {
    attach(context) {
      once('ai-listing-bulk-intake-sets', '#ai-bulk-intake-sets-root', context).forEach((root) => {
        const runtime = (drupalSettings && drupalSettings.aiListingBulkIntake) || {};
        const form = root.closest('form');
        const progressRoot = document.getElementById('ai-bulk-intake-upload-progress');
        const stageButton = form ? form.querySelector('#ai-bulk-intake-stage-submit') : null;
        const processButton = form ? form.querySelector('input[name="process_staged_sets"]') : null;
        const chunkUploadUrl = runtime.chunkUploadUrl || '';
        const chunkSizeBytes = Number(runtime.chunkSizeBytes || 1024 * 1024);
        const maxParallelSets = Math.max(1, Number(runtime.maxParallelSets || 2));
        const maxChunkAttempts = Math.max(1, Number(runtime.maxChunkAttempts || 5));
        const chunkRequestTimeoutMs = Math.max(5000, Number(runtime.chunkRequestTimeoutMs || 30000));
        const maxChunkRetryWindowMs = Math.max(30000, Number(runtime.maxChunkRetryWindowMs || 20 * 60 * 1000));
        const debugDefaultFailureSetKey = String(runtime.debugSimulateFailureSetKey || '').trim();
        const debugDefaultFailureChunkIndex = Number.parseInt(runtime.debugSimulateFailureChunkIndex, 10);
        const debugDefaultFailureOnce = runtime.debugSimulateFailureOnce !== false;
        let activeBatchSets = [];
        let batchStartedAtMs = 0;
        const pageBatchToken = 'batch-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
        const completedSetKeys = new Set();
        const uploadIdByFileKey = new Map();

        const parseFaultInjectionConfig = function () {
          const config = {
            enabled: debugDefaultFailureSetKey !== '' && Number.isInteger(debugDefaultFailureChunkIndex) && debugDefaultFailureChunkIndex >= 0,
            setKey: debugDefaultFailureSetKey,
            chunkIndex: Number.isInteger(debugDefaultFailureChunkIndex) ? debugDefaultFailureChunkIndex : -1,
            once: debugDefaultFailureOnce,
            consumed: false,
          };
          try {
            const raw = window.localStorage.getItem('aiListingBulkIntakeFaultInjection');
            if (!raw) {
              return config;
            }
            const parsed = JSON.parse(raw);
            const setKey = String(parsed.setKey || '').trim();
            const chunkIndex = Number.parseInt(parsed.chunkIndex, 10);
            if (setKey !== '' && Number.isInteger(chunkIndex) && chunkIndex >= 0) {
              config.enabled = true;
              config.setKey = setKey;
              config.chunkIndex = chunkIndex;
              config.once = parsed.once !== false;
            }
          }
          catch (error) {
            // Ignore malformed test override and fall back to defaults.
          }
          return config;
        };

        const faultInjection = parseFaultInjectionConfig();

        const createSetRow = function (nextIndex) {
          const row = document.createElement('div');
          row.setAttribute('data-ai-bulk-intake-set-row', String(nextIndex));
          row.style.marginBottom = '14px';

          const label = document.createElement('label');
          label.textContent = 'Set ' + nextIndex + ' images';
          label.style.display = 'block';
          label.style.fontWeight = '600';
          label.style.marginBottom = '6px';

          const input = document.createElement('input');
          input.type = 'file';
          input.name = 'intake_sets[set_' + nextIndex + '][]';
          input.multiple = true;
          input.accept = 'image/*';
          input.style.display = 'block';
          input.classList.add('ai-bulk-intake-file-input');

          row.appendChild(label);
          row.appendChild(input);
          root.appendChild(row);
          return row;
        };

        const ensureNextRow = function () {
          const rows = Array.from(root.querySelectorAll('[data-ai-bulk-intake-set-row]'));
          if (rows.length === 0) {
            createSetRow(1);
            return;
          }
          const lastRow = rows[rows.length - 1];
          const lastInput = lastRow.querySelector('input[type="file"]');
          if (!lastInput) {
            createSetRow(rows.length + 1);
            return;
          }
          if (lastInput.files && lastInput.files.length > 0) {
            createSetRow(rows.length + 1);
          }
        };

        root.addEventListener('change', function (event) {
          const target = event.target;
          if (!target || target.tagName !== 'INPUT' || target.type !== 'file') {
            return;
          }
          if (target.files && target.files.length > 0) {
            ensureNextRow();
          }
        });

        const normalizeSetKey = function (inputName, fallbackIndex) {
          if (!inputName) {
            return 'set_' + fallbackIndex;
          }
          const match = inputName.match(/intake_sets\[(set_\d+)\]\[\]/);
          return match ? match[1] : ('set_' + fallbackIndex);
        };

        const listSelectedSets = function () {
          const inputs = Array.from(root.querySelectorAll('input[type="file"]'));
          const sets = [];
          let idx = 0;
          inputs.forEach((input) => {
            idx += 1;
            if (!input.files || input.files.length === 0) {
              return;
            }
            const setKey = normalizeSetKey(input.name, idx);
            if (completedSetKeys.has(setKey)) {
              return;
            }
            const files = Array.from(input.files);
            const totalBytes = files.reduce((sum, file) => sum + Number(file.size || 0), 0);
            sets.push({
              setKey,
              setId: setKey + '-' + pageBatchToken,
              files,
              totalBytes,
              uploadedBytes: 0,
              state: 'queued',
            });
          });
          return sets;
        };

        const formatBytes = function (bytes) {
          const value = Number(bytes || 0);
          if (value >= 1024 * 1024 * 1024) {
            return (value / (1024 * 1024 * 1024)).toFixed(2) + ' GiB';
          }
          if (value >= 1024 * 1024) {
            return (value / (1024 * 1024)).toFixed(1) + ' MiB';
          }
          if (value >= 1024) {
            return (value / 1024).toFixed(1) + ' KiB';
          }
          return value + ' B';
        };

        const renderProgressRows = function (sets) {
          if (!progressRoot) {
            return;
          }
          progressRoot.innerHTML = '';
          const heading = document.createElement('h3');
          heading.textContent = 'Upload progress';
          progressRoot.appendChild(heading);

          const totalRow = document.createElement('div');
          totalRow.className = 'ai-bulk-intake-progress-row ai-bulk-intake-progress-row-total';
          totalRow.setAttribute('data-set-id', '__total__');

          const totalLabel = document.createElement('div');
          totalLabel.className = 'ai-bulk-intake-progress-label';
          totalLabel.textContent = 'Total batch';

          const totalStatus = document.createElement('div');
          totalStatus.className = 'ai-bulk-intake-progress-status';
          totalStatus.textContent = 'Queued';

          const totalBarWrap = document.createElement('div');
          totalBarWrap.className = 'ai-bulk-intake-progress-bar-wrap';

          const totalBar = document.createElement('div');
          totalBar.className = 'ai-bulk-intake-progress-bar';
          totalBar.style.width = '0%';
          totalBarWrap.appendChild(totalBar);

          totalRow.appendChild(totalLabel);
          totalRow.appendChild(totalStatus);
          totalRow.appendChild(totalBarWrap);
          progressRoot.appendChild(totalRow);

          sets.forEach((set) => {
            const row = document.createElement('div');
            row.className = 'ai-bulk-intake-progress-row';
            row.setAttribute('data-set-id', set.setId);

            const label = document.createElement('div');
            label.className = 'ai-bulk-intake-progress-label';
            label.textContent = set.setKey;

            const status = document.createElement('div');
            status.className = 'ai-bulk-intake-progress-status';
            status.textContent = 'Queued';

            const barWrap = document.createElement('div');
            barWrap.className = 'ai-bulk-intake-progress-bar-wrap';

            const bar = document.createElement('div');
            bar.className = 'ai-bulk-intake-progress-bar';
            bar.style.width = '0%';
            barWrap.appendChild(bar);

            row.appendChild(label);
            row.appendChild(status);
            row.appendChild(barWrap);
            progressRoot.appendChild(row);
          });
        };

        const formatEta = function (seconds) {
          if (!Number.isFinite(seconds) || seconds < 0) {
            return 'calculating';
          }
          const sec = Math.max(0, Math.ceil(seconds));
          const hh = Math.floor(sec / 3600);
          const mm = Math.floor((sec % 3600) / 60);
          const ss = sec % 60;
          if (hh > 0) {
            return hh + 'h ' + String(mm).padStart(2, '0') + 'm';
          }
          if (mm > 0) {
            return mm + 'm ' + String(ss).padStart(2, '0') + 's';
          }
          return ss + 's';
        };

        const updateTotalProgress = function () {
          if (!progressRoot || activeBatchSets.length === 0) {
            return;
          }
          const totalBytes = activeBatchSets.reduce((sum, set) => sum + Number(set.totalBytes || 0), 0);
          const uploadedBytes = activeBatchSets.reduce((sum, set) => sum + Number(set.uploadedBytes || 0), 0);
          const failedCount = activeBatchSets.filter((set) => set.state === 'failed').length;
          const doneCount = activeBatchSets.filter((set) => set.state === 'done').length;
          const ratio = totalBytes > 0 ? (uploadedBytes / totalBytes) : 1;
          const pct = Math.max(0, Math.min(100, Math.floor(ratio * 100)));
          const elapsedSec = (Date.now() - batchStartedAtMs) / 1000;
          const speed = elapsedSec > 0 ? (uploadedBytes / elapsedSec) : 0;
          const remainingBytes = Math.max(0, totalBytes - uploadedBytes);
          const etaSeconds = speed > 0 ? (remainingBytes / speed) : Number.POSITIVE_INFINITY;

          const totalRow = progressRoot.querySelector('[data-set-id="__total__"]');
          if (!totalRow) {
            return;
          }
          const status = totalRow.querySelector('.ai-bulk-intake-progress-status');
          const bar = totalRow.querySelector('.ai-bulk-intake-progress-bar');

          let state = 'Uploading';
          if (failedCount > 0) {
            state = 'Failed';
          }
          else if (doneCount === activeBatchSets.length) {
            state = 'Complete';
          }

          if (status) {
            let extra = '';
            if (state === 'Uploading') {
              extra = ', ETA ' + formatEta(etaSeconds);
            }
            status.textContent = state + ` (${pct}%, ${formatBytes(uploadedBytes)} / ${formatBytes(totalBytes)}${extra})`;
            status.classList.toggle('is-error', failedCount > 0);
          }
          if (bar) {
            bar.style.width = Math.max(0, Math.min(100, ratio * 100)) + '%';
            bar.classList.toggle('is-error', failedCount > 0);
          }
        };

        const updateProgressRow = function (set, stateText, ratio, isError) {
          if (!progressRoot) {
            return;
          }
          const row = progressRoot.querySelector('[data-set-id="' + set.setId + '"]');
          if (!row) {
            return;
          }
          const status = row.querySelector('.ai-bulk-intake-progress-status');
          const bar = row.querySelector('.ai-bulk-intake-progress-bar');
          if (status) {
            const pct = Math.max(0, Math.min(100, Math.floor(ratio * 100)));
            status.textContent = stateText + ' (' + pct + '%, ' + formatBytes(set.uploadedBytes) + ' / ' + formatBytes(set.totalBytes) + ')';
            status.classList.toggle('is-error', Boolean(isError));
          }
          if (bar) {
            bar.style.width = Math.max(0, Math.min(100, ratio * 100)) + '%';
            bar.classList.toggle('is-error', Boolean(isError));
          }
          updateTotalProgress();
        };

        const postChunk = async function (set, file, chunkBlob, chunkIndex, chunkCount, uploadId, chunkStart) {
          const chunkStartedAt = Date.now();
          let attempt = 0;
          let lastFailureMessage = 'unknown';
          while (true) {
            attempt += 1;
            if (faultInjection.enabled && !faultInjection.consumed
              && set.setKey === faultInjection.setKey
              && chunkIndex === faultInjection.chunkIndex) {
              if (faultInjection.once) {
                faultInjection.consumed = true;
              }
              throw new Error(`Injected terminal upload failure for ${set.setKey} chunk ${chunkIndex + 1}/${chunkCount}`);
            }
            const elapsedMs = Date.now() - chunkStartedAt;
            if (elapsedMs > maxChunkRetryWindowMs) {
              throw new Error(
                `chunk failed after retry window ${Math.round(maxChunkRetryWindowMs / 1000)}s at attempt ${attempt}: ${lastFailureMessage}`
              );
            }
            let response = null;
            try {
              const body = new FormData();
              body.append('set_id', set.setId);
              body.append('upload_id', uploadId);
              body.append('chunk_index', String(chunkIndex));
              body.append('chunk_count', String(chunkCount));
              body.append('chunk_start', String(chunkStart));
              body.append('file_size', String(file.size));
              body.append('file_name', file.name);
              body.append('chunk', chunkBlob, file.name + '.part');
              const controller = new AbortController();
              const timeoutId = window.setTimeout(() => controller.abort(), chunkRequestTimeoutMs);
              response = await fetch(chunkUploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body,
                signal: controller.signal,
              });
              window.clearTimeout(timeoutId);
            }
            catch (error) {
              if (attempt >= maxChunkAttempts) {
                // After the fast-attempt budget is exhausted, keep retrying
                // within the retry window so transient tunnel drops do not
                // hard-fail the entire set.
                const errorMessage = error && error.message ? error.message : String(error);
                lastFailureMessage = 'fetch error: ' + errorMessage;
                const ratio = set.totalBytes > 0 ? (set.uploadedBytes / set.totalBytes) : 0;
                updateProgressRow(
                  set,
                  `Retrying chunk ${chunkIndex + 1}/${chunkCount} after fetch error (${attempt} attempts): ${errorMessage}`,
                  Math.min(1, ratio),
                  true
                );
              }
              const expBase = Math.min(1000 * (2 ** (attempt - 1)), 15000);
              const jitterMs = Math.floor(Math.random() * 750);
              const backoffMs = expBase + jitterMs;
              await new Promise((resolve) => window.setTimeout(resolve, backoffMs));
              continue;
            }

            let payload = null;
            try {
              payload = await response.json();
            }
            catch (error) {
              payload = null;
            }
            if (response.ok && payload && payload.ok === true) {
              return Number(payload.bytes_accepted || 0);
            }
            const message = payload && payload.error ? payload.error : ('HTTP ' + response.status);
            if (attempt >= maxChunkAttempts) {
              lastFailureMessage = 'server reject: ' + message;
              const ratio = set.totalBytes > 0 ? (set.uploadedBytes / set.totalBytes) : 0;
              updateProgressRow(
                set,
                `Retrying chunk ${chunkIndex + 1}/${chunkCount} after server reject (${attempt} attempts): ${message}`,
                Math.min(1, ratio),
                true
              );
            }
            const expBase = Math.min(1000 * (2 ** (attempt - 1)), 15000);
            const jitterMs = Math.floor(Math.random() * 750);
            const backoffMs = expBase + jitterMs;
            await new Promise((resolve) => window.setTimeout(resolve, backoffMs));
          }
        };

        const getUploadId = function (set, file) {
          const fileKey = set.setKey + '::' + file.name + '::' + String(file.size);
          if (uploadIdByFileKey.has(fileKey)) {
            return uploadIdByFileKey.get(fileKey);
          }
          const uploadId = 'up-' + pageBatchToken + '-' + set.setKey + '-' + Math.random().toString(36).slice(2, 10);
          uploadIdByFileKey.set(fileKey, uploadId);
          return uploadId;
        };

        const uploadFileInChunks = async function (set, file) {
          const chunkCount = Math.max(1, Math.ceil(file.size / chunkSizeBytes));
          const uploadId = getUploadId(set, file);
          for (let chunkIndex = 0; chunkIndex < chunkCount; chunkIndex += 1) {
            const start = chunkIndex * chunkSizeBytes;
            const end = Math.min(file.size, start + chunkSizeBytes);
            const chunkBlob = file.slice(start, end);
            const acceptedBytes = await postChunk(set, file, chunkBlob, chunkIndex, chunkCount, uploadId, start);
            set.uploadedBytes += acceptedBytes;
            const ratio = set.totalBytes > 0 ? (set.uploadedBytes / set.totalBytes) : 1;
            updateProgressRow(set, 'Uploading', ratio, false);
          }
        };

        const uploadSingleSet = async function (set) {
          set.state = 'uploading';
          updateProgressRow(set, 'Uploading', 0, false);
          for (const file of set.files) {
            await uploadFileInChunks(set, file);
          }
          set.state = 'done';
          completedSetKeys.add(set.setKey);
          updateProgressRow(set, 'Complete', 1, false);
        };

        const uploadSetsWithConcurrency = async function (sets) {
          const queue = sets.slice();
          const workers = [];
          const worker = async function () {
            while (queue.length > 0) {
              const set = queue.shift();
              if (!set) {
                return;
              }
              try {
                await uploadSingleSet(set);
              }
              catch (error) {
                set.state = 'failed';
                updateProgressRow(set, 'Failed: ' + (error && error.message ? error.message : 'unknown error'), Math.min(1, set.totalBytes > 0 ? set.uploadedBytes / set.totalBytes : 0), true);
              }
            }
          };
          const count = Math.min(maxParallelSets, sets.length);
          for (let i = 0; i < count; i += 1) {
            workers.push(worker());
          }
          await Promise.all(workers);
        };

        const setBusy = function (busy) {
          if (stageButton) {
            stageButton.disabled = busy;
          }
          if (processButton) {
            processButton.disabled = busy;
          }
          root.querySelectorAll('input[type="file"]').forEach((input) => {
            input.disabled = busy;
          });
        };

        const recordBatchHistory = function (sets) {
          const entry = {
            at: Date.now(),
            setKeys: sets.map((set) => set.setKey),
          };
          try {
            const raw = window.sessionStorage.getItem('aiListingBulkIntakeBatchHistory');
            const existing = raw ? JSON.parse(raw) : [];
            const next = Array.isArray(existing) ? existing : [];
            next.push(entry);
            while (next.length > 10) {
              next.shift();
            }
            window.sessionStorage.setItem('aiListingBulkIntakeBatchHistory', JSON.stringify(next));
          }
          catch (error) {
            // Telemetry only; ignore storage failures.
          }
        };

        const runChunkedStageUpload = async function () {
          if (!chunkUploadUrl) {
            window.alert('Chunk upload endpoint is not configured.');
            return;
          }
          const sets = listSelectedSets();
          if (sets.length === 0) {
            if (completedSetKeys.size > 0) {
              window.alert('No failed sets remain to retry. Process staged sets or reload to start a new batch.');
            }
            else {
              window.alert('No files selected.');
            }
            return;
          }

          recordBatchHistory(sets);
          renderProgressRows(sets);
          activeBatchSets = sets;
          batchStartedAtMs = Date.now();
          updateTotalProgress();
          setBusy(true);
          await uploadSetsWithConcurrency(sets);
          setBusy(false);
          updateTotalProgress();

          const failed = sets.filter((set) => set.state === 'failed');
          if (failed.length > 0) {
            return;
          }
          window.location.reload();
        };

        if (form && stageButton) {
          stageButton.addEventListener('click', function (event) {
            event.preventDefault();
            runChunkedStageUpload();
          });
          form.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            if (submitter && submitter.name === 'process_staged_sets') {
              return;
            }
            event.preventDefault();
            runChunkedStageUpload();
          });
        }

        ensureNextRow();
      });
    },
  };
})(Drupal, once, drupalSettings);
