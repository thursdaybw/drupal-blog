# Extract Framesmith runtime out of the bevansbench.com monolith

## Problem

`bevansbench.com` currently carries Framesmith transcription runtime concerns in the production image (Python + `vastai`) to keep queue workers running. This creates deployment coupling and makes the host site image harder to reason about.

## Findings

- Framesmith provisioning/transcription is operationally distinct from the core bevansbench.com web runtime.
- Current production support in this repo is transitional and should be explicitly treated as such.
- We need a defined cutover plan so queue workers, secrets, and runtime tooling move without regressions.

## Outcomes

- Framesmith runtime is delivered from a dedicated stack/repo.
- bevansbench.com production image returns to host-site-only runtime dependencies.
- ownership boundaries are clear for deploy, secrets, and incident response.

## Acceptance criteria

- create architecture decision note for Framesmith stack separation and boundaries
- define target runtime and deployment model for Framesmith workers/services
- move transcription/provisioning runtime dependencies out of bevansbench.com image
- validate end-to-end transcription job flow on the new stack
- remove temporary Framesmith runtime notes/dependencies from bevansbench.com once cutover is complete

## Implementation notes

- keep transitional comments in `Dockerfile.prod` until cutover is complete
- include rollback plan and queue-drain strategy during migration
- define where `VAST_API_KEY`, SSH keys, and worker scheduling live after extraction

## Next action

- draft the extraction ADR and migration checklist, including queue cutover and rollback steps
