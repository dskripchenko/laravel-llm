# Providers and routes

## Providers (`llm_providers`)

| Column | |
|---|---|
| `name` | unique, shown in the log |
| `base_url` | the OpenAI-compatible root, **including `/v1`**: `http://127.0.0.1:11434/v1` (Ollama), `http://host:8000/v1` (vLLM), `http://127.0.0.1:1234/v1` (LM Studio), `http://127.0.0.1:8080/v1` (llama.cpp server) |
| `api_key` | optional; encrypted with the application key; sent as `Authorization: Bearer` |
| `models` | informational list of models the provider serves (for an admin UI) |
| `limits` | `timeout` (seconds, overrides `llm.http.timeout`), `max_output_tokens` (caps `max_tokens` of every request) |
| `is_cloud` | the provider is outside the tenant's infrastructure |
| `cloud_enabled` | an administrator allowed the cloud provider |
| `is_enabled` | a disabled provider is skipped |

### Cloud providers

> **Warning.** A prompt contains the tenant's data — issue texts, pages,
> comments. Sending it to a cloud API moves that data out of the tenant's
> infrastructure. The package therefore never routes to a provider with
> `is_cloud = true` unless `cloud_enabled = true` as well. Expose
> `cloud_enabled` only to administrators who may make that decision, and
> mark every non-local endpoint as `is_cloud`: the package cannot tell
> where a URL leads.

A refused cloud route is skipped (with a warning in the application log) and
its fallback is tried; when nothing is left, `CloudProviderNotEnabled` is
thrown and nothing is sent.

## Routes (`llm_routes`)

A route maps a **purpose** — `chat`, `summarize`, `classify`, `extract`,
`embed`, `rerank` (`Dskripchenko\Llm\Purpose`) — to a provider and a model.

| Column | |
|---|---|
| `purpose` | |
| `scenario` | optional: a route only for one scenario (e.g. `wiki.answer`); without a scenario route the purpose route is used |
| `provider_id`, `model` | |
| `fallback_route_id` | the route tried when this one is unavailable |
| `options` | default request options for this model (`temperature`, `num_ctx`…); a prompt's or a caller's options override them |
| `is_active` | an inactive route is skipped (its fallback is still followed) |

The **entry route** of a purpose is the active route that no other route of
the same purpose falls back to (the oldest, if several). From there the chain
follows `fallback_route_id` (cycles are cut, at most 16 steps).

### What falls back

| Failure | Result |
|---|---|
| connection refused, DNS, timeout | next route |
| HTTP 5xx, 429 | next route |
| a body that is not JSON, no choices | next route |
| HTTP 4xx (other) | `ProviderRejected`, no fallback — the request itself is wrong |
| a stream that broke after the first delta | `ProviderUnavailable`, no fallback — the caller already has part of the answer |
| every route failed | `ProviderUnavailable` (`isTransient() === true`) |

Every HTTP attempt is a row in the call log, failed ones included.

## The protocol

- `POST {base}/chat/completions` with `model`, `messages`, the options,
  `tools` / `tool_choice` when given, and for a structured answer
  `response_format: {type: json_schema, json_schema: {name, schema, strict: true}}`.
  Streaming adds `stream: true` and `stream_options: {include_usage: true}`
  and reads server-sent events.
- `POST {base}/embeddings` with `model` and `input` (a list).
- `POST {base}/rerank` with `model`, `query`, `documents`, `top_n` — the
  Jina/Cohere shape served by vLLM and llama.cpp server. Ollama and LM Studio
  have no rerank endpoint; route `rerank` to a server that does.

Tool calls come back in `LlmResult::$toolCalls` (name, decoded arguments);
the package does not run tools or loop — pass the results back with
`history()` and call again.

## Configuration (`config/llm.php`)

| Key | Default | |
|---|---|---|
| `http.timeout`, `http.connect_timeout` | 120, 5 | seconds |
| `structured.max_attempts` | 3 | total attempts for a structured answer |
| `prompts.presets` | `[]` | preset classes |
| `log.enabled` | true | |
| `log.keep_bodies_days` | 30 | |
| `log.schedule_prune` | true | schedule `llm:prune` daily |
| `quota.tenant_tokens_per_day`, `quota.project_tokens_per_day` | null | default limits |
| `effect.attempts` | 3 | must match `RunLlm`'s retry policy |
