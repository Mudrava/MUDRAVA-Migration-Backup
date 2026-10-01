# 100 GiB backup and restore test

On 29 September 2026, MUDRAVA exported and restored a real 100 GiB random file
between separate local WordPress sites. The source and restored file had the
same SHA-256 hash. This test demonstrates that the measured workload completed;
it does not predict every host, database or live site.

| Measurement | Result |
|---|---|
| File size | 107,374,182,400 bytes |
| Allocated bytes | 107,374,194,688 bytes, not a sparse fixture |
| PHP | 8.3, 128 MB memory limit on both sites |
| Destination WordPress | 7.1.2 |
| Archive parts | 15 |
| Total archive bytes | 107,955,441,322 |
| Logical archive bytes | 108,028,858,361 |
| Restored files | 3,787 |
| Restored database rows | 234 |
| Reported URL transform failures | 0 |

Source and restored file SHA-256:

```
d6fca34c5f5d68f68ec0fb184e28d2292171852999297b3e55677486fb344f3a
```

Export was driven through the owner's Chrome. Restore ticks ran as the web
server user in separate WP-CLI processes. Chrome showed progress, completion
and the restored page. The destination created a verified restore point before
import. The full archive was verified before replacement began.

This is a file dominated workload with a small database. It does not prove
large database row compatibility, a consistent snapshot during concurrent
writes, or a comparative speed advantage. The source fixture was removed after
export verification to make room for the destination copy. No throughput
comparison or multi-terabyte result is claimed.

The detailed test record is retained with the project's private audit evidence.
This run is historical evidence, not a test of every subsequent release artifact.
Use the [current release receipt](free-release-candidate-2026-09-30.md) for final
ZIP checks. See [large sites](large-sites.md) and
[compatibility](compatibility.md) before a production migration.
