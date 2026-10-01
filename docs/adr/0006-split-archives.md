# ADR-0006: Split archives - one logical backup, N physical parts

Status: accepted (2026-09-25)

## Decision
Splitting happens only at frame boundaries. Part 1 carries the full header;
parts 2+ carry a continuation header (`MUDRAVAP` magic + archive UUID +
part number). Global frame sequence continues across parts. UI shows ONE
backup with N parts; importer explicitly reports missing / wrong / duplicate /
corrupt parts. Default OFF or AUTO; presets 2/4/8 GB + custom.

## Consequences
- Users moving files via FAT32 / email / broken upload limits keep the simple
  mental model.
- Resume logic must track (part, offset) pairs; golden fixtures include
  split sets with deliberately missing/reordered parts.
