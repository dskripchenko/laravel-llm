# Security policy

This package sends tenant data to language models and stores prompts,
answers and provider API keys. Please report vulnerabilities privately.

## Reporting

Use GitHub's private vulnerability reporting:
**Security → Report a vulnerability** on
[github.com/dskripchenko/laravel-llm](https://github.com/dskripchenko/laravel-llm/security/advisories/new),
or write to denskrp90@gmail.com. Please include the version, the provider (Ollama, vLLM, …) and the
steps to reproduce. Do not open a public issue for a vulnerability.

You will get an answer within five working days. A confirmed issue is fixed in
a patch release of every supported line, and published as a GitHub security
advisory once the release is out.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | ✅ |

## What is guaranteed

- **Cloud providers are opt-in per row.** A provider marked `is_cloud` is
  skipped by routing until an administrator sets `cloud_enabled`; when no
  other route is left, the call fails with `CloudProviderNotEnabled` and
  nothing is sent. Marking a provider as cloud is the host's responsibility:
  the package cannot tell where a URL leads.
- **User text is data.** Text passed through `data()` or a `data` variable
  is wrapped in a block delimited by a random boundary generated for each
  request (a text cannot close its block), and the system message tells the
  model never to follow instructions inside such blocks. This lowers the
  risk of prompt injection; it does not remove it — never let a model's
  answer act without the checks a person's input would get (see `Mode`).
- **API keys are encrypted at rest** (Laravel's `encrypted` cast with the
  application key), hidden from serialization and never written to the call
  log.
- **Bodies are temporary.** Prompts and responses are kept in `llm_calls`
  for `llm.log.keep_bodies_days` (30) and then pruned to metrics.

## Scope

In scope: routing and the cloud opt-in, data blocks around user text,
structured-output validation, the call log and its pruning, quotas, and the
storage of API keys. Out of scope: the model servers themselves, the
behaviour of a model, and a host's own prompts and use of the answers.
