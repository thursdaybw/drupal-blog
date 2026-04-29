# Reusable transcription boundary inventory

Status: working inventory for the reusable transcription subsystem extraction

## Purpose

Framesmith is the first product using the transcription workflow, but the transcription task/worker subsystem is not inherently Framesmith-specific.

This document records the current semantic debt while the code still lives inside `compute_orchestrator`. It should move with the transcription subsystem when that subsystem is extracted into its own module/project boundary.

The target architecture is:

```text
Framesmith product
  -> reusable transcription/task subsystem
  -> compute_orchestrator runtime lease API
  -> Whisper runtime
```

`compute_orchestrator` should not own transcription tasks, uploads, transcripts, or product status endpoints. It should own runtime lease infrastructure only.

## Target ownership

### compute_orchestrator

Owns generic compute runtime infrastructure:

- runtime lease API;
- runtime lease response mapping;
- runtime/provider pool state;
- workload readiness;
- provider lifecycle;
- lease expiry, renewal, release, and idle reap;
- operator diagnostics.

### reusable transcription/task subsystem

Owns reusable transcription workflow infrastructure:

- transcription task storage;
- upload intake and local file preparation;
- transcription launch/worker orchestration;
- transcription runner lifecycle;
- transcription executor interface;
- fake transcription executor for tests/smoke flows;
- Whisper HTTP executor;
- selectable executor strategy;
- Whisper runtime client interface and direct/HTTP implementations;
- task status and result storage;
- retry/failure state.

### Framesmith product

Owns Framesmith-specific product experience:

- Framesmith browser user interface;
- Framesmith product routes or compatibility wrappers;
- product-specific status/result shaping;
- product permissions and user experience;
- timeline/compiler/media editing concerns.

## Current file classification

### Reusable transcription/task infrastructure currently misnamed as Framesmith

These should move out of `compute_orchestrator` and be renamed away from `Framesmith*` unless they remain product-specific adapters:

| Current file | Target concept | Notes |
| --- | --- | --- |
| `src/Service/FramesmithTranscriptionTaskStore.php` | `TranscriptionTaskStore` | Reusable task state/storage. |
| `src/Service/FramesmithTranscriptionTaskStoreInterface.php` | `TranscriptionTaskStoreInterface` | Reusable task store contract. |
| `src/Service/FramesmithTranscriptionLauncher.php` | `TranscriptionLauncher` | Reusable launch orchestration. |
| `src/Service/FramesmithTranscriptionLauncherInterface.php` | `TranscriptionLauncherInterface` | Reusable launcher contract. |
| `src/Service/FramesmithTranscriptionRunner.php` | `TranscriptionRunner` | Reusable worker lifecycle. |
| `src/Service/FramesmithTranscriptionExecutorInterface.php` | `TranscriptionExecutorInterface` | Reusable executor contract. |
| `src/Service/FramesmithFakeTranscriptionExecutor.php` | `FakeTranscriptionExecutor` | Reusable fake executor for tests/smoke mode. |
| `src/Service/FramesmithWhisperHttpTranscriptionExecutor.php` | `WhisperHttpTranscriptionExecutor` | Reusable Whisper executor. |
| `src/Service/FramesmithSelectableTranscriptionExecutor.php` | `SelectableTranscriptionExecutor` | Reusable runtime/fake selector. |
| `src/Command/FramesmithTranscriptionCommand.php` | `TranscriptionCommand` or product wrapper | Worker command is reusable if it runs generic transcription tasks. |

### Runtime client seam

These names are now mostly concept-aligned, but still live inside `compute_orchestrator` and may belong with the reusable transcription subsystem once moved:

| Current file | Target concept | Notes |
| --- | --- | --- |
| `src/Service/WhisperRuntimeClientInterface.php` | `WhisperRuntimeClientInterface` | Reusable transcription subsystem depends on this; implementation talks to compute. |
| `src/Service/DirectWhisperRuntimeClient.php` | `DirectWhisperRuntimeClient` | Transitional rollback path. Loud warning is intentional. |
| `src/Service/HttpWhisperRuntimeClient.php` | `HttpWhisperRuntimeClient` | Desired direction for extracted clients. |
| `src/Service/FramesmithRuntimeLeaseManagerInterface.php` | transitional compatibility shim | Should be removed once callers type against `WhisperRuntimeClientInterface`. |

### Framesmith product adapters / compatibility surface

These may stay Framesmith-named if they are deliberately preserving the current browser-facing API:

| Current file or route | Target concept | Notes |
| --- | --- | --- |
| `src/Controller/FramesmithTranscriptionController.php` | Framesmith product adapter or compatibility controller | Can delegate to reusable transcription subsystem while preserving `/api/framesmith/...` routes. |
| `/api/framesmith/transcription/start` | Framesmith compatibility route | Browser-facing product API. |
| `/api/framesmith/transcription/upload` | Framesmith compatibility route | Browser-facing product API. |
| `/api/framesmith/transcription/status` | Framesmith compatibility route | Browser-facing product API. |
| `/api/framesmith/transcription/result` | Framesmith compatibility route | Browser-facing product API. |
| `use framesmith transcription api` | Framesmith product permission | Product permission unless generic transcription API is exposed later. |

### Tests and smoke coverage

These should move with whichever concept they test:

| Current file | Target concept | Notes |
| --- | --- | --- |
| `tests/src/Unit/FramesmithTranscriptionRunnerTest.php` | reusable transcription unit test | Rename with runner. |
| `tests/src/Unit/FramesmithFakeTranscriptionExecutorTest.php` | reusable transcription unit test | Rename with fake executor. |
| `tests/src/Unit/FramesmithWhisperHttpTranscriptionExecutorTest.php` | reusable transcription unit test | Rename with Whisper executor. |
| `tests/src/Kernel/FramesmithTranscriptionApiKernelTest.php` | product adapter or compatibility test | Tests browser-facing Framesmith API. |
| `tests/src/ExistingSite/FramesmithDrushBootstrapSmokeTest.php` | reusable worker/bootstrap or product smoke | Classify when command boundary is moved. |
| `tests/src/ExistingSiteJavascript/FramesmithFakeModeBrowserSmokeTest.php` | Framesmith product smoke | Browser-facing product flow. |
| `tests/src/ExistingSiteJavascript/FramesmithStagingBrowserSmokeTest.php` | Framesmith product smoke | Browser-facing product flow. |
| `tests/src/ExistingSiteJavascript/FramesmithBrowserSmokeFlowTrait.php` | Framesmith product smoke helper | Browser-facing product flow. |

## Recommended extraction sequence

1. Create a reusable transcription module boundary, probably `media_transcription` or `transcription_worker`.
2. Move task store, launcher, runner, executors, runtime clients, and worker command into that boundary with concept names.
3. Keep the existing Framesmith browser-facing routes as compatibility wrappers that delegate into the reusable transcription subsystem.
4. Keep `compute_orchestrator` focused on runtime lease infrastructure.
5. Switch the reusable transcription subsystem from direct runtime client to HTTP runtime client in staging first.
6. Remove the transitional direct runtime client only after remote client behaviour is proven and rollback no longer needs the direct path.

## Do not do

- Do not move generic transcription task state into `compute_orchestrator` long term.
- Do not name reusable worker/task services after Framesmith.
- Do not expose compute runtime lease tokens to browser JavaScript.
- Do not make Framesmith extraction depend on direct PHP service access to `VllmPoolManager`.
