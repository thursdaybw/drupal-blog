# Capture compute_orchestrator SSH probe history to local JSONL log

Date opened: 2026-04-23
Owner: bevan

Why:
- Current Drupal watchdog entries for SSH probes are too low-signal during live debugging.
- We need an operator-readable local artifact that shows the exact remote command, timing, exit status, stdout, stderr, and exception for each probe.
- A structured local log is the quickest path toward a later Drupal UI tab for pool and SSH probe history.

Definition of done:
- [ ] `SshProbeExecutor` appends probe invocations and results to a local JSONL log file.
- [ ] The log includes timestamp, probe name, host, port, user, remote command, exit code, transport status, stdout, stderr, and exception.
- [ ] Logging never exposes the private SSH key material.
- [ ] Logging failures do not break the actual probe flow.
- [ ] The log path is deterministic and easy to tail from the local DDEV container.

Next action:
- Add file-backed JSONL logging inside `SshProbeExecutor` so live Vast runs can be debugged without scraping watchdog placeholders.
