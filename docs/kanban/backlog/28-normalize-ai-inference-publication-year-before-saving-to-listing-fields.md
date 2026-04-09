# Normalize AI inference publication year before saving to listing fields

## Problem

After the generic Qwen node was brought back to a working runtime configuration, the real `ai:process-new` workflow for listing `3128` completed metadata and condition inference but then failed while saving the inferred publication year into Drupal.

Observed failure:

- inferred publication year value: `1941, 1948`
- database error: `SQLSTATE[22001]: String data, right truncated`
- failing field: `field_publication_year_value`

This is a Drupal-side normalization/storage bug, not a compute-orchestrator or generic-vLLM image bug.

## Outcomes

- AI inference output can contain ambiguous or multi-year publication strings without crashing listing saves
- publication year values are normalized to the field contract before entity persistence
- malformed or multi-valued year strings produce deterministic behavior instead of database exceptions

## Acceptance criteria

- reproduce the failure with a real or fixture-backed inference result containing `1941, 1948`
- identify the exact field length/schema and the application layer where inferred metadata is mapped onto listing fields
- normalize publication year into the allowed field format before save
- decide and document the rule for ambiguous year strings:
  - first year only, or
  - empty value, or
  - separate normalized/raw storage strategy
- add automated coverage for the chosen rule
- verify `ai:process-new` no longer crashes on the reproduced case

## Notes

- This card is intentionally separate from the generic vLLM pool/image work.
- The compute side is now proven far enough to return real metadata and condition output for the listing workflow.
