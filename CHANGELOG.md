# Changelog

All notable changes to this project are documented in this file. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-27

### Added

- `llm.run_migrations` (`LLM_RUN_MIGRATIONS`): a schema-per-tenant host turns the
  automatic migrations off and publishes them into its tenant path.
- OpenAI-compatible transport over Laravel's Http client:
  `/chat/completions` (plain and SSE streaming, tools, `response_format`
  with a JSON schema), `/embeddings`, `/rerank`.
- Providers and purpose routes in the database, with fallback chains; a
  scenario may have its own route. Timeouts, connection errors, 5xx and 429
  fall back; 4xx does not. API keys are encrypted at rest.
- Cloud providers are refused unless explicitly enabled (`cloud_enabled`).
- Prompt presets in code with typed variables; tenant overrides in the
  database with versions, activation and rollback.
- User-supplied text is wrapped in data blocks with a per-request random
  boundary, plus a system instruction not to follow anything inside them.
- Structured answers validated by a built-in JSON schema subset; invalid
  answers are retried a bounded number of times with the errors fed back.
- Call log (scenario, purpose, provider, model, tokens, duration, status,
  subject, quota keys); bodies pruned after 30 days by `llm:prune`
  (scheduled daily).
- Daily token quotas per tenant key and project key, checked before a call.
- `RunLlm`, the `llm.run` effect for dskripchenko/process-engine:
  idempotent by the effect key, answers with `llm.done` / `llm.failed`.
- `Mode` (`apply`, `suggest`, `approve`) for human-in-the-loop flows.
- `Llm::fake()` with sequences, per-scenario answers, failures, and
  assertions.

[0.1.0]: https://github.com/dskripchenko/laravel-llm/releases/tag/v0.1.0
