# Core PHP integration contract

`Mudrava\Migration\Integration\CoreApi::CONTRACT_VERSION` is `1`.
This is an internal PHP interface for trusted installed add-ons, not a REST
endpoint. An add-on owns its user capability, consent and configuration checks.
The core contains no license checks or commerce SDK.

- `startExport(excludes, encrypted, splitBytes)` starts the existing local
  export engine with source metadata. It respects job locking and Multisite
  rejection. Credentials are supplied separately on ticks and never persisted.
- `status()` returns an allowlisted summary or null. It excludes internal
  checkpoints, site metadata, paths and arbitrary extension state.
- `tickExport(archiveId, password)` advances only the named export. The runner
  checks kind and ID both before and after taking the atomic job lock. A newer
  import or unrelated export raises `MUDRAVA_JOB_CHANGED` instead of advancing.
- `completedExport(archiveId)` describes the **current** completed verified
  export, including private file paths, byte sizes and an identity token. Missing,
  incomplete or replaced parts fail. Do not expose these paths to users or services.

The descriptor is a discovery result, not a file lease. An add-on must keep its
delivery state durable, prevent conflicting cleanup, pin/recheck identities when
reading, and verify actual remote content. The identity token detects metadata
and sampled content changes; it is not a full SHA-256 of an archive part.

Historical exports are not covered by this initial interface. Core import state
is not writable through it. Import lifecycle guards, destination-local add-on
state protection and delivery leases are still required before releasing an
automation add-on. No transport-provider, frame-handler or job-finished hook is
currently part of the supported runtime contract.

## Protecting installed add-on code

Call `CoreApi::protectDirectory(__DIR__)` from the add-on's `plugins_loaded`
callback on every request (cron and REST included). It registers an existing,
canonical absolute directory for this request. Export inventory, import
preflight and the importer share the same protection. Historical archives can
contain that directory; their code is skipped while ordinary files still
restore. Renamed add-on directories work without hardcoded Pro slugs.

Protection is request-local and must be registered before a job is advanced.
A directory outside the site's root, or the root itself, does not exclude the
site. This protects executing code; it does not sanitize database options,
cron events or third-party licensing SDK data. Pro must keep destination
identity, credentials and automation state outside transferable site content.

## Private add-on data

`CoreApi::privateAddonDirectory('addon-namespace')` returns a stable canonical
private directory under the core archive storage, outside WordPress/content
roots. Namespace: lowercase letter followed by up to 63 lowercase letters,
digits or hyphens. Directory mode is 0700; linked directory entries are rejected.
Use it for destination-bound state that must survive content imports and must
never enter exported DB rows/files. The add-on owns its file locking, encryption,
permissions, key recovery and lifecycle. Uninstall/retention must not delete
another add-on's namespace. This helper does not provide cloud backup, host
failure recovery, credential encryption or a licensing service.

## Shared administration panels

`CoreApi::registerAdminPanel($slug, $renderer, $label)` registers a trusted
installed add-on callback. Allowed slots: schedule, storage, notifications and
pro. Manual migration and diagnostic panels cannot be replaced. Renderers escape
their own output and check relevant capabilities. Exceptions discard partial
markup and allow the optional informational fallback. Register on plugins_loaded
before the admin page is rendered. The clean distribution includes only actual
registered add-on panels. Optional promotional descriptions are absent.

## Recoverable export starts

`captureState()` returns an allowlisted job summary and an opaque token.
Pass a persisted 64-character hex request ID and that token to `startExport()`
to claim the captured idle slot. A matching export request returns the existing
job even after completion. A replaced slot fails with MUDRAVA_JOB_CHANGED under
the core lock. Persist the request before starting. This contract does not
provide a scheduler, encryption secret source, delivery queue or file lease.
