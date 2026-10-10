# Publisher registration (telling Harvest what you publish)

A consumer that renders folios publicly is a **publisher**. Harvest shows a folio as available on a
publisher only after that publisher confirms it. `Survos\FolioBundle\Publisher\PublisherRegistrar`
is the one client for that; consumers do not call the endpoints themselves.

The contract is Harvest's `docs/folio-changelog.md`, section "Publisher registration and receipts".

## Pieces

| Type | Role |
|---|---|
| `PublisherRegistrar` | `begin`, `reset`, `confirm`, `withdraw`, `withdrawAbsent`, `flush`, `updateSelection` |
| `PublisherState` | the durable part: generation, unacknowledged receipts, what is confirmed. Lives in your checkpoint |
| `PublicationReceipt` / `ReceiptState` | one published/withdrawn event for one variant (datasetKey + artifactType + artifactCode) |
| `ReceiptStatus` | Harvest's answer: `Applied`, `Unchanged`, `Superseded`, `Unknown`. All are acknowledgements |
| `PublisherSelection` / `SelectionMode` | subscription intent (`All`, `Tags`, `Datasets`, `Manual`), never availability |
| `PublisherRegistration`, `Publication` | response DTOs (registration; `GET /api/datasets/{key}/publications`) |
| `PublisherApiClient` | transport only, with the publisher token |

## How a consumer wires it

1. **Config** (`survos_folio.yaml`). `dataset_api` must be enabled; the publisher uses its server.

   ```yaml
   survos_folio:
       dataset_api:
           enabled: true
           server: '%env(HARVEST_SERVER)%'
           token: '%env(HARVEST_READ_TOKEN)%'
       publisher:
           enabled: true
           code: ink                                  # this app's key in Harvest HARVEST_PUBLISHER_TOKENS
           environment: '%env(HARVEST_PUBLISHER_ENV)%' # prod, local, …: own generation each
           token: '%env(HARVEST_PUBLISHER_TOKEN)%'    # not the read token
           label: Ink
           base_url: 'https://inkstory.org'          # optional; confirmed URLs must be on this host
           selection: { mode: all }                   # sent on reset
   ```

2. **In the sync command**, inside the existing lock, around `FolioCatalogClient::synchronize()`:

   ```php
   $saved = $this->checkpoints->load();                       // your one JSON row
   $publisher = $this->registrar->begin($saved['publisher'] ?? null); // resets on first run
   $full = $full || $publisher->resyncRequired();             // after a reset or a 409

   try {
       $next = $this->catalog->synchronize($saved, $full, function (array $entries) use ($publisher, $io): void {
           foreach ($entries as $entry) {
               if (!$this->wants($entry)) { continue; }
               $this->publishLocally($io, $entry);             // pullEntry + local records; throws on failure
               // Only now, with the revision actually published. Same call for initial and
               // incremental sync; an unchanged revision+URL is a no-op.
               $this->registrar->confirm($publisher, $entry, $this->publicUrl($entry));
           }
           // Explicit withdrawals: deselected folios, feed 404s, a single local purge.
           $this->registrar->withdrawAbsent($publisher, array_filter($entries, $this->wants(...)));
       });
       if ($full) { $publisher->markResynced(); }
   } finally {
       $result = $this->registrar->flush($publisher);         // never throws for a Harvest outage
       $next ??= $saved;                                      // failed sync: keep the old cursor…
       $next['publisher'] = $publisher->toArray();            // …but always keep the receipt queue
       $this->checkpoints->save($next);
   }
   ```

   `flush()` sends receipts in batches of 500. Anything not acknowledged stays in
   `$next['publisher']` and goes out again on the next run. `$result->isComplete()`,
   `->remaining`, `->staleGeneration` and `->error` belong in `<app>:sync:status`.

3. **When local state is lost** (database purged, volume replaced), call `$registrar->reset()`
   and save the returned state. Harvest drops this publisher/environment's receipts, and the next
   run does a full sync and confirms everything again. A checkpoint with no `publisher` key
   resets automatically in `begin()`.

4. **Selection changes** go through `$registrar->updateSelection(new PublisherSelection(SelectionMode::Tags, tags: [...]))`.
   This changes intent only; withdraw what you stop publishing.

## Rules the registrar enforces

- **409 (stale generation)**: queued receipts are discarded, never resent under the new number.
  The new generation is adopted and `resyncRequired()` becomes true. If Harvest is unreachable at
  that moment, the next `begin()` sees the mismatch and does the same.
- **Revisions are opaque**: equality only. Any different revision is a new confirmation.
- **Durable retry**: one pending receipt per variant (the latest wins). Each keeps its original
  `occurredAt` (microseconds), so a late resend can never override a newer event.
- **`Unknown`**: Harvest no longer has the variant, so it is dropped from `confirmed`.
- A URL off `base_url`'s host, or a published receipt without a revision, throws in `confirm()`
  rather than poisoning a batch with a 400 later.

## Reading publications (folio/show)

`folio_publications(datasetKey)` (Twig) returns the current `Publication`s for a dataset, one per
URL, from `GET /api/datasets/{key}/publications`. Results are cached for 60 s; an outage returns
`[]`. `folio/show` renders a "Read on {name}" link per publication. If there are none, it falls
back to `folio_reader_url()`, which is null unless `reader_server` is configured, as it no longer
is in Harvest. With neither, the page renders without links.
