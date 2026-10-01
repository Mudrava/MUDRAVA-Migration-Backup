# Free / Pro boundary

## Principles

1. **Free is complete, not crippled.** The core engine - streaming
   format, resumable jobs, encryption, verification, serialized-safe
   rewrite, chunked uploads, split archives - ships in the Free plugin
   under GPL-2.0-or-later. No size caps, no job caps, no nag that
   blocks work.
2. **Pro is a separate plugin, in a separate repository.** It never
   patches Free files. It registers through documented hooks and
   replaces injectable services.
3. **WP.org compliance.** The Free plugin in the WordPress.org directory
   contains no premium code, no license-key checks, no remote gates.
   Pro is distributed separately through the product's approved commerce channel.

## Pro v1 (in development)

| Feature | Why Pro |
|---|---|
| Periodic backup schedules | Repeated client-site maintenance |
| S3 / S3-compatible offsite delivery and retries | Customer-controlled remote copies |
| Retention and delivery reporting | Ongoing backup operations |
| Subscription updates and support | Compatibility and human assistance |

Annual plans: $59 for 3 production sites, $129 for 15, $249 for 50. Free
requires no registration or site license. Pro requires a paid activation for
new automation jobs. Expiry stops new automation; running jobs finish safely,
archives are retained and manual restore stays available through Free.

SFTP, direct transfer, Multisite orchestration and incremental backups are
future candidates, not shipped or promised Pro v1 capabilities.

## Extension points (Free)

The initial supported PHP surface is `Integration\CoreApi`, contract version 1:
start a local export, read an allowlisted status, tick only the named export,
and describe its current completed verified archive. See [extension API](extension-api.md).
Previously listed transport-provider/frame-handler/job-finished hooks are not
implemented and are not part of the supported contract. Delivery leases and
import lifecycle isolation must be completed before an automation release.

## Upgrade path

Installing Pro never changes Free behavior unless a feature is
explicitly enabled in Pro's own settings. Uninstalling Pro returns the
site to the Free workflow. Pro v1 will use the same self-contained full-archive
format; no premium reader is required to restore that format. Future incremental
formats require their own explicit reader/compatibility contract. Format source:
[`.mudrava format`](mudrava-format.md).
