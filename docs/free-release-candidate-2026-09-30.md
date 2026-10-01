# Free 1.0.0 review candidate

Validation date: September 30, 2026. WordPress.org approved the corrected review
candidate on October 2, 2026. This report records the reviewed artifact; the
publication build updates the plugin and support links to WordPress.org.

## Artifact

Installable archive: `mudrava-migration-backup-1.0.0.zip`.
Clean profile, 63 runtime files, no premium promotion panels.

SHA256:
`9fda43b876b5781b625a52f27f5165f1fc177bbbd7dc825b43bf462495e157c7`

Two builds produced identical ZIP bytes. Every installed runtime file on the
validation site matched the final ZIP by SHA256. Distribution validation passed.

## Checks

- `composer check`: PHPCS, PHPStan, headers and 580 tests with 2,615 assertions passed.
- `node --check assets/admin.js`: passed after the progress label correction.
- Plugin Check on the exact installed candidate: “Success: Checks complete. No errors found.”
- Test host: WordPress 7.1.2, PHP 7.4.32, Elementor, ACF and Polylang active.
- Visible Chrome export on the revised build: archive `backup-localhost-20260930-164801-mxptvi` completed.
- Download and a 92.8 MB chunked upload through Chrome completed. The earlier
  restore `up-munx4v4u-s7xoc5` completed and preserved destination restore
  point `backup-localhost-20260930-094421-8dkynp`. A repeat restore on this
  build was not completed because Chrome's confirmation dialog stopped responding.
- All five screenshots in `wordpress-org/assets/` were retaken from the local
  WordPress site without the browser automation pointer. Screenshot 5 now shows
  the archive list and the readme caption matches it.

The final JavaScript change clears the previous operation's phase label before
starting another operation. The final CSS change keeps dark text on the amber
archive download hover state. Neither changes the archive or restore engine.

Historical large file and compatibility evidence is linked from the README.
This small browser run does not repeat the 100 GiB benchmark or establish new
compatibility coverage. Moderation remains a separate manual decision.

## Publication

The GitHub review candidate provides a downloadable installation ZIP and source.
Directory banners, icons and screenshots belong in the WordPress.org SVN assets
directory after approval. They are not installed as runtime screenshots.

## Submission result

WordPress.org accepted this ZIP on September 30, 2026. Automated scanning: Pass.
Review status: Approved on October 2, 2026. Assigned slug: `mudrava-migration-backup`.
The first upload was rejected because Plugin URI and Author URI were identical.
The reviewed Plugin URI pointed to the public repository. The publication build
points to https://wordpress.org/plugins/mudrava-migration-backup/; Author URI
remains https://mudrava.com/en/.
This header change passed distribution validation, reproducibility and Plugin Check again.
The review email reported that the PHP plugin description ended at "using one"
because it wrapped onto extra lines. It also required WordPress upload handling
instead of direct `move_uploaded_file()`. The revised build uses a single-line
description and `wp_handle_upload()` in private staging. Its upload path was
exercised through Chrome and Plugin Check reports zero findings. WordPress.org
approved that corrected submission and completed the review.

The revised 1.0.0 ZIP was uploaded to the existing WordPress.org review on
September 30, 2026. The developer page lists it as the newest submitted file
with the correction summary. The submission receipt is kept in the private
product operations records.
