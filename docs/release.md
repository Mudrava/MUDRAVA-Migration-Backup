# Release process

## Versioning

SemVer. The format version (`MUDRAVA\0` magic + header layout) is a
separate contract from the plugin version: a minor plugin release must
never break reading older archives; breaking the format requires a major
version and a reader that still accepts the previous format.

## Pre-release checklist

```bash
composer check                 # tests + phpstan + phpcs
bin/dist.sh                    # build the runtime zip
bin/verify-dist.sh             # lint every php file inside the zip
```

- Golden fixture unchanged (`tests/GoldenArchives`) unless the format
  change is intentional and documented in `docs/mudrava-format.md`.
- `readme.txt` stable tag and `Version:` header match the tag.
- CHANGELOG.md updated.

## Tagging

```bash
git tag -s v1.0.0 -m "v1.0.0"
git push origin v1.0.0
```

The `release.yml` workflow is currently manual. Run it in the release tag
context to build and check the ZIP, verify identical bytes across two builds,
and attach it to a GitHub release. Pushing a tag alone does not run this workflow.

## WordPress.org

The Free plugin is committed to the plugin's SVN `trunk` (and tagged
under `tags/1.0.0`) from the release zip. Only runtime files ship:
`mudrava-migration-backup.php`, `uninstall.php`, `readme.txt`,
`LICENSE`, `includes/`, `assets/`, `languages/`. Tests, tooling, and
dev dependencies never enter the SVN tree.

## Pro add-on

Pro lives in a separate private repository and ships as a standalone
plugin that requires the Free core. It never patches Free files; it
registers through the Free plugin's extension points. Pro is not
submitted to WP.org (per the directory's premium-boundary rules).

## Distribution profiles

`bash bin/dist.sh` builds the clean Free ZIP by default. It excludes optional
premium descriptions from runtime PHP and regenerates the translation template
from the exact staged files. Manual migration, backup, restore, encryption,
splitting and verification are identical in both profiles. No account, license,
quota or runtime promotion switch is added.

`bash bin/dist.sh 1.0.0 with-pro` builds a separate `-with-pro.zip` containing
informational add-on panels. This is not a paid implementation or an installer.
Keep this variant out of the first WordPress.org submission. Any future directory
update must independently comply with the directory guidelines; approval of the
clean artifact does not approve later changes.

Verify an explicit artifact with `bash bin/verify-dist.sh dist/<filename>.zip`.
Reproducibility: `bash bin/verify-reproducible-dist.sh 1.0.0 clean` (or `with-pro`).
The first submission must be finalized only after assets, public source review
and the exact-ZIP Plugin Check and browser checks.
