# Aufrufprotokoll und Kontingente

## Das Aufrufprotokoll (`llm_calls`)

Eine Zeile pro HTTP-Aufruf an einen Anbieter — ein Ausweichen auf eine andere
Route oder ein erneuter Versuch wegen des Schemas erzeugt weitere Zeilen.

| Spalte | |
|---|---|
| `scenario`, `purpose`, `prompt_key`, `prompt_version` | |
| `provider_id`, `provider`, `model`, `attempt` | `attempt` ist der Versuch der strukturierten Ausgabe |
| `prompt_tokens`, `completion_tokens`, `total_tokens`, `duration_ms` | wie vom Anbieter gemeldet |
| `status` | `ok`, `invalid_output`, `unavailable`, `rejected` |
| `error` | |
| `subject_type`, `subject_id` | die mit `about()` übergebene Morph-Referenz (Strings: Integer-, UUID- und ULID-Schlüssel passen) |
| `tenant_key`, `project_key` | die Kontingentschlüssel |
| `idempotency_key` | |
| `on_behalf_of` | in wessen Namen der Aufruf erfolgte (`onBehalfOf()`) |
| `attribution` | `project`, `task`, `run`, mit `attribute()` übergeben |
| `cost` | aus der Antwort des Anbieters (`usage.cost`), sonst nach seinen `prices`; null – unbekannt |
| `request`, `response`, `result` | Inhalte: das gesendete JSON (nie der API-Schlüssel), die Rohantwort, das geparste Ergebnis |
| `bodies_pruned_at` | |

`Dskripchenko\Llm\Models\Call` ist ein gewöhnliches Model mit einer
`subject()`-Relation — Berichte lassen sich darauf aufbauen.

### Bereinigung

`php artisan llm:prune [--days=N]` setzt `request`, `response` und `result`
bei Zeilen, die älter als `llm.log.keep_bodies_days` (30) sind, auf null und
stempelt `bodies_pruned_at`; die Metriken bleiben erhalten. Mit
`llm.log.schedule_prune` (standardmäßig aktiv) plant das Paket den Befehl
täglich ein; bei einem Schema pro Mandant das abschalten und den Befehl je
Mandant aus dem eigenen Scheduler ausführen. `Llm::prune($days)` erledigt
dasselbe aus dem Code.

Mit `llm.log.bodies` = false (`LLM_LOG_BODIES=false`) werden gar keine
Inhalte gespeichert: die Zeile eines Aufrufs hält Metriken, Gegenstand und
Status, `bodies_pruned_at` wird sofort gesetzt — für Anwendungen, deren
Prompts enthalten, was nicht gespeichert werden darf.

## Kontingente

Tägliche Token-Budgets je **Mandantenschlüssel** und je
**Projektschlüssel**. Der Host legt fest, auf welche Schlüssel ein Aufruf
angerechnet wird:

```php
use Dskripchenko\Llm\Facades\Llm;
use Dskripchenko\Llm\Quota\QuotaScope;
use Dskripchenko\Llm\Transport\CallMeta;

Llm::resolveQuotaUsing(fn (CallMeta $meta, ?string $subjectType, int|string|null $subjectId) => new QuotaScope(
    tenantKey: tenant()->key,
    projectKey: Project::keyFor($subjectType, $subjectId),   // null = nicht pro Projekt begrenzt
    projectLimit: null,                                      // null = llm.quota.project_tokens_per_day
));
```

- Die Limits sind standardmäßig `llm.quota.tenant_tokens_per_day` und
  `llm.quota.project_tokens_per_day` (null = unbegrenzt); ein `QuotaScope`
  kann eigene mitbringen.
- Der Verbrauch ist die Summe von `total_tokens` im Protokoll seit
  Tagesbeginn (Zeitzone der Anwendung) — fehlgeschlagene und ungültige
  Versuche zählen mit.
- Die Prüfung läuft **vor jedem Aufruf** (und vor jedem erneuten Versuch
  wegen des Schemas): `QuotaExceeded` (`scope`, `key`, `limit`, `used`) wird
  geworfen und das Modell nicht aufgerufen. Ein Aufruf, der unter dem Limit
  beginnt, kann darüber enden; der nächste wird abgelehnt.
- `->quota(new QuotaScope(...))` an einer Anfrage bzw. `quota:` bei
  `embed()` / `rerank()` umgeht den Resolver.
