# Performance notes

## Current memory behavior

- File data is read in 8 MiB chunks and written as separate archive frames.
  Before each next chunk, the exporter re-reads and hashes the previous chunk
  to detect same-size edits across ticks. This adds roughly one extra source
  read per file chunk; its effect on 100 GB–2 TB throughput is unmeasured.
  The reader also bounds each frame by its declared logical size.
- Database export first fetches byte lengths for up to 500 rows, then fetches
  only the leading rows that fit a 4 MiB payload target and the current PHP
  memory headroom. A single larger row may exceed that target when the host
  has enough memory; otherwise export returns `MUDRAVA_MEMORY_LIMIT` before
  fetching it. WordPress `$wpdb` still buffers the selected payload rows.
  The format rejects an encoded row above its 256 MiB frame ceiling and does
  **not** stream a single database value in chunks.
- URL rewriting processes each value in memory. Large values and serialized
  structures need separate memory and correctness measurements.

The 1 GiB actual-byte file migration under a 64 MB PHP limit and a separate
file larger than 4 GiB are retained in private test records. They demonstrate file
streaming on those fixtures, not bounded memory for every database shape.
The product specification's large-site targets remain release gates.

## Tick and throughput behavior

Export and import resume at durable frame boundaries. A large database query
or single row may still exceed a request's time budget before the next
boundary. The extra length query adds database work per batch. End-to-end
throughput for 100 GB, 500 GB, 1 TB and 2 TB sites
has not been measured. Do not use an estimated migration time as a promise.

| Setting | Current value | Consequence |
|---|---:|---|
| File data chunk | 8 MiB | One file frame can require several working copies in PHP |
| Database length scan | Up to 500 rows | Adds one length query before each payload query |
| Database payload query | Up to 4 MiB target | PHP headroom can reduce the batch; one larger row is allowed if it fits |
| Database row-frame target | 4 MiB | Several row frames can come from one query |
| Hard frame ceiling | 256 MiB | One encoded row above this size is rejected |
| DEFLATE level | 6 | Incompressible payloads are stored raw |

Measure export and restore on the intended host, with representative database
rows, before publishing throughput or memory claims. The published measurements
and compatibility cases are in the [100 GiB report](benchmark-100-gib.md)
and [compatibility guide](compatibility.md).
