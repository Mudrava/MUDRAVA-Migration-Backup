# Free launch artwork

The owner selected round 2 option 03 Archive on 30 September 2026.
The M uses the original studio letterform. Its vertical position was raised
3.505 units in the 256 unit viewBox to center it optically within the light
container. The rounded lower corners are accounted for with a one unit optical
adjustment above the bounding box center.

`icon.svg` is the selected source. `logo-review.png` shows 256, 128, 48 and 32 px
renders. The directory icons have been updated. `banner.svg` and `preview.png`
contain the current directory banner with the selected Archive mark and the
original studio wordmark in white.

Banner copy: Free WordPress migration. No paid size cap.
Supporting copy states streaming .mudrava backups, resumable exports, optional
encryption and verification before restore. The archive caption conveys
portability. Host resources and format limits remain visible.

The banner uses a single font family with two sizes, 32 and 12 in its 772 unit
viewBox. Both headline lines have the same size and weight. Four features share
one grid, one size and one weight. The studio wordmark retains its original
vector letterforms. The selected palette is charcoal, warm white and amber.

Rebuild with `node branding/render-banner.cjs` with Sharp available through
Node module resolution. The script reads the selected icon and studio source.

The rendered assets in `wordpress-org/assets/` target the official directory
sizes. These images belong in the top level SVN assets directory after approval,
not inside the runtime plugin ZIP. Capture real Free UI screenshots separately.

Artwork by MUDRAVA. GPL-2.0-or-later, as covered by the repository LICENSE.
