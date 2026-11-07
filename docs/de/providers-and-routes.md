# Anbieter und Routen

## Anbieter (`llm_providers`)

| Spalte | |
|---|---|
| `name` | eindeutig, wird im Protokoll angezeigt |
| `base_url` | die OpenAI-kompatible Wurzel, **einschließlich `/v1`**: `http://127.0.0.1:11434/v1` (Ollama), `http://host:8000/v1` (vLLM), `http://127.0.0.1:1234/v1` (LM Studio), `http://127.0.0.1:8080/v1` (llama.cpp-Server) |
| `api_key` | optional; mit dem Anwendungsschlüssel verschlüsselt; gesendet als `Authorization: Bearer` |
| `models` | informative Liste der Modelle, die der Anbieter bereitstellt (für eine Admin-Oberfläche) |
| `limits` | `timeout` (Sekunden, überschreibt `llm.http.timeout`), `max_output_tokens` (deckelt `max_tokens` jeder Anfrage) |
| `is_cloud` | der Anbieter liegt außerhalb der Infrastruktur des Mandanten |
| `cloud_enabled` | ein Administrator hat den Cloud-Anbieter freigegeben |
| `is_enabled` | ein deaktivierter Anbieter wird übersprungen |

### Cloud-Anbieter

> **Warnung.** Ein Prompt enthält die Daten des Mandanten — Artikel,
> Kommentare, Nachrichten. Werden sie an eine Cloud-API gesendet, verlassen diese
> Daten die Infrastruktur des Mandanten. Das Paket leitet deshalb nie an einen
> Anbieter mit `is_cloud = true` weiter, solange nicht auch
> `cloud_enabled = true` gesetzt ist. `cloud_enabled` nur Administratoren
> zugänglich machen, die diese Entscheidung treffen dürfen, und jeden nicht
> lokalen Endpunkt als `is_cloud` markieren: Das Paket kann nicht erkennen,
> wohin eine URL führt.

Eine abgelehnte Cloud-Route wird übersprungen (mit einer Warnung im
Anwendungslog) und ihre Ausweichroute versucht; bleibt nichts übrig, wird
`CloudProviderNotEnabled` geworfen und nichts gesendet.

## Routen (`llm_routes`)

Eine Route ordnet einem **Zweck** — `chat`, `summarize`, `classify`,
`extract`, `embed`, `rerank` (`Dskripchenko\Llm\Purpose`) — einen Anbieter
und ein Modell zu.

| Spalte | |
|---|---|
| `purpose` | |
| `scenario` | optional: eine Route nur für ein Szenario (z. B. `wiki.answer`); ohne Szenario-Route wird die Route des Zwecks verwendet |
| `provider_id`, `model` | |
| `fallback_route_id` | die Route, die versucht wird, wenn diese nicht verfügbar ist |
| `options` | Standard-Anfrageoptionen für dieses Modell (`temperature`, `num_ctx` …); die Optionen eines Prompts oder Aufrufers überschreiben sie |
| `is_active` | eine inaktive Route wird übersprungen (ihrer Ausweichroute wird dennoch gefolgt) |

Die **Einstiegsroute** eines Zwecks ist die aktive Route, auf die keine
andere Route desselben Zwecks ausweicht (bei mehreren die älteste). Von dort
folgt die Kette `fallback_route_id` (Zyklen werden abgeschnitten, höchstens
16 Schritte).

### Wann ausgewichen wird

| Fehler | Ergebnis |
|---|---|
| Verbindung abgelehnt, DNS, Timeout | nächste Route |
| HTTP 5xx, 429 | nächste Route |
| ein Body, der kein JSON ist, keine choices | nächste Route |
| HTTP 4xx (sonstige) | `ProviderRejected`, kein Ausweichen — die Anfrage selbst ist fehlerhaft |
| ein Stream, der nach dem ersten Delta abgebrochen ist | `ProviderUnavailable`, kein Ausweichen — der Aufrufer hat bereits einen Teil der Antwort |
| alle Routen sind fehlgeschlagen | `ProviderUnavailable` (`isTransient() === true`) |

Jeder HTTP-Versuch ist eine Zeile im Aufrufprotokoll, fehlgeschlagene
eingeschlossen.

## Das Protokoll

- `POST {base}/chat/completions` mit `model`, `messages`, den Optionen,
  `tools` / `tool_choice`, sofern angegeben, und für eine strukturierte
  Antwort
  `response_format: {type: json_schema, json_schema: {name, schema, strict: true}}`.
  Streaming fügt `stream: true` und `stream_options: {include_usage: true}`
  hinzu und liest Server-Sent Events.
- `POST {base}/embeddings` mit `model` und `input` (einer Liste).
- `POST {base}/rerank` mit `model`, `query`, `documents`, `top_n` — die
  Jina/Cohere-Form, die vLLM und der llama.cpp-Server bereitstellen. Ollama
  und LM Studio haben keinen Rerank-Endpunkt; `rerank` an einen Server
  routen, der einen hat.

Tool-Aufrufe kommen in `LlmResult::$toolCalls` zurück (Name, dekodierte
Argumente); das Paket führt keine Tools aus und läuft in keiner Schleife —
die Ergebnisse mit `history()` zurückgeben und erneut aufrufen.

## Konfiguration (`config/llm.php`)

| Schlüssel | Standard | |
|---|---|---|
| `http.timeout`, `http.connect_timeout` | 120, 5 | Sekunden |
| `structured.max_attempts` | 3 | Versuche insgesamt für eine strukturierte Antwort |
| `prompts.presets` | `[]` | Preset-Klassen |
| `log.enabled` | true | |
| `log.keep_bodies_days` | 30 | |
| `log.schedule_prune` | true | `llm:prune` täglich einplanen |
| `quota.tenant_tokens_per_day`, `quota.project_tokens_per_day` | null | Standardlimits |
| `effect.attempts` | 3 | muss der Wiederholungsrichtlinie von `RunLlm` entsprechen |
