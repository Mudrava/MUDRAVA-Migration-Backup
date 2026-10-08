# Accessibility statement

Last reviewed: 2026-10-08.

MUDRAVA Migration & Backup is an administrator-only plugin. All of its UI lives
in the WordPress dashboard and is reachable only by users with migration
capabilities. It does not alter front-end visitor markup.

## What we implement

- The operations screen uses a tabbed interface with `role="tablist"`,
  `role="tab"`, `aria-controls` and an `aria-label` describing the tab group;
  inactive panels are hidden with the `hidden` attribute so they leave the
  accessibility tree.
- The job progress dialog traps focus while it is open and returns focus to
  the control that opened it when it closes.
- Success, warning and danger notices carry `role="alert"` so screen readers
  announce job results without polling.
- Decorative images and icons are marked `aria-hidden="true"`.
- All interactive controls are native buttons and inputs; the plugin does not
  replace them with click-only divs, so keyboard operation follows the
  WordPress admin baseline.
- Every user-facing string passes through `esc_html`/`esc_attr` and the
  standard text domain, keeping labels translatable.
- Long-running jobs report progress as text (percent and step name), not as a
  color-only indicator.

## Known limitations

- Job progress updates arrive over polling; screen readers announce them when
  the notice region changes, not continuously.
- The file picker uses the browser's native dialog; accessibility there is
  whatever your browser provides.

## Feedback

Accessibility defects are treated as bugs. Report them through the
WordPress.org support forum for this plugin or at
[support@mudrava.com](mailto:support@mudrava.com) with the screen, browser and
assistive technology you used.
