# Make Deployment Diagnostics Less Opaque

Date opened: 2026-03-22
Owner: bevan

Why:
- Critical deploy failures are being summarized away behind Ansible task abstraction.
- The current playbook can skip important Drupal actions like `updb`, `cim`, and `cr` without surfacing the real failure clearly enough.
- Deployment tooling does not need to be shell-transparent, but it must expose the real reason when something important fails.

Definition of done:
- [ ] Review current deploy and activate tasks and identify where critical failure detail is hidden or downgraded.
- [ ] Ensure bootstrap, database update, config import, cache rebuild, and health-check failures surface actionable stdout and stderr clearly.
- [ ] Remove misleading fallback messaging that masks real deploy problems.
- [ ] Decide which deploy steps should remain in Ansible tasks and which should move into explicit scripts for clearer behavior.
- [ ] Document the chosen deploy transparency standard so future changes do not reintroduce opaque failure handling.

Next action:
- Audit `ops/ansible/deploy_image.yml` for critical steps that currently hide or soften real failures, starting with Drupal bootstrap and post-activate verification.
