# Changelog

All notable changes to this project are documented in this file. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [0.3.0] - 2026-11-14

### Added

- Attribution: `onBehalfOf()` and `attribute(project:, task:, run:)` on a
  request, `attribution:` on `Llm::embed()` and `Llm::rerank()`. Kept in the
  call log (`on_behalf_of`, `attribution`); sent as headers
  (`llm.attribution.headers`: `X-On-Behalf-Of`, `X-Project`, `X-Task`,
  `X-Run`) only to a provider with `attribution` set — a gateway that keeps
  accounts by them, never a model vendor.
- Cost in the call log (`cost`): from the provider's answer (`usage.cost`),
  else by the provider's `prices` per million tokens (per model or `*`).

### Upgrading

- A new migration adds `attribution` and `prices` to `llm_providers` and
  `on_behalf_of`, `attribution` and `cost` to `llm_calls`. With
  `llm.run_migrations` off, publish it again (`--tag=llm-migrations`).

## [0.2.0] - 2026-11-11

### Added

- `llm.log.bodies` (`LLM_LOG_BODIES`): false keeps no prompt, response or
  result in the call log — only metrics, the subject and the status.

## [0.1.0] - 2026-11-07

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
