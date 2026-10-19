# Semantic Search PR Split

## PR A: Core AI Embedding Primitives

### Scope
- Add internal core AI actions and responses:
  - `embed_text`
  - `embed_image`
  - `embed_media`
- Extend AI action storage schema:
  - `ai_action_embed_text`
  - `ai_action_embed_image`
  - `ai_action_embed_media`
- Add upgrade step and core version bump for the new tables.
- Extend manager typed-action allowlist and AI usage filter options.
- Add OpenAI + Ollama provider support for embedding actions:
  - action registration
  - embedding action settings forms
  - text embedding processors
  - structured unsupported responses for image/media processors
- Add/adjust core/provider tests for new actions and provider action lists.

### Validation
- PHP lint all touched core AI and provider files.
- Run targeted AI action/provider PHPUnit groups in CI.

## PR B: `search_semantic` Integration

### Scope
- Replace skeleton semantic engine with indexing/query pipeline:
  - chunk text
  - call `core_ai` embedding actions
  - store/search in vector store
  - map vector hits to `core_search\document`
- Add embedding client wrapper for `core_ai\manager` calls.
- Add semantic plugin settings and language strings needed by the engine.
- Update semantic engine tests for new constructor/config behavior.

### Validation
- PHP lint semantic plugin files.
- Run semantic plugin unit tests and vector-store tests.

## Follow-up PR C (Optional)

### Scope
- Native multimodal embedding support for image/media where provider/model supports it.
- Media extraction/transcoding pipeline if needed for unsupported models.
