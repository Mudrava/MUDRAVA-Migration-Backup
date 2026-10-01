## What

- [ ] Describe the change and the problem it solves.

## Verification

- [ ] `composer check` passes (phpcs + phpstan + phpunit).
- [ ] New behavior covered by a test (red first, then green).
- [ ] `npx playwright test` passes against the lab (if user-visible).

## Compatibility

- [ ] No archive-format change, or golden fixture + docs updated
  deliberately.
- [ ] PHP 7.4 floor respected (CI matrix proves it).

## Notes for reviewers
