# Security policy

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

## Reporting a vulnerability

Please report privately by e-mail to denskrp90@gmail.com, not in a public
issue.
