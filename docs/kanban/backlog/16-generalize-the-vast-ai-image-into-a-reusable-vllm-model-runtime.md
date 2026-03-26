# Generalize The VAST AI Image Into A Reusable vLLM Model Runtime

Date opened: 2026-03-25
Owner: bevan

Why:
- The current VAST AI image is tuned around the present image-inference workflow and is too narrow for the next stage of the product.
- The platform now needs a more general-purpose AI model runtime that can host different models, including larger LLMs, without being hard-wired to one current use case.
- A reusable vLLM-based image is the right next abstraction: general model-serving infrastructure first, model-specific selection second.

Definition of done:
- [ ] Define the responsibilities and boundaries of the general-purpose AI model image.
- [ ] Remove assumptions that the image is only for the current image-inference model path.
- [ ] Standardize runtime configuration so model choice is driven by environment or deployment config rather than a hard-coded model target.
- [ ] Ensure the image can support vLLM-served LLM workloads in addition to the current narrower inference path.
- [ ] Document the expected deployment contract for the generalized image, including model selection, runtime resources, and startup behavior.

Next action:
- Inspect the current VAST AI image build and runtime assumptions, then identify what is image-inference-specific versus what should become generic vLLM runtime behavior.

Links:
- Context: current direction is toward a general-purpose “AI model” Docker image, explicitly centered on vLLM rather than a single fixed model.
