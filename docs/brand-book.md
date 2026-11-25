# Pharmaco

Pharmaco runs the selling, stock and money of a pharmaceutical business on one set of books and closes each day against the cash in the drawer. This is a small first system, made for the landing page: ten colours in one light theme, seven text styles, four spacing steps and three radii. There are no components and no logo yet; the name is set in plain type (Newsreader 600, `ink`).

## The one idea: two columns, one book

The same product serves pharmacies and distributors, so every landing page section is two columns side by side. Pharmacy is always on the left on `pharmacy-tint`, distributor always on the right on `distributor-tint`. Never swap sides and never use one side's colour for the other. Each column opens with a `label` eyebrow that names its audience ("FOR PHARMACIES", "FOR DISTRIBUTORS"), so the colour is never the only cue. What both share (the header, the shared-facts section, the footer) sits on `paper` in `ink`, with no side colour.

## Colour

- `paper` is the page; `surface` is for cards and receipts on top of it. Separate them with a `line` hairline, not a shadow.
- `ink` for text and for the primary button (label in `paper`). `muted` for secondary text.
- `pharmacy` and `distributor` are for labels, links, icons and small solid fills inside their own column. Large areas use the tints.
- `alert` means overdue, short or negative. Nothing else is red.
- **Strong colours** is the same palette for a dull screen: darker text, visible lines, deeper tints, a step stronger accent. It is a choice of each computer (device chip → Screen). Any colour added to `tokens.css` must still read in both.

## Type

- Newsreader (`display`, `heading`, `subheading`) for headlines: it should read like a printed statement.
- IBM Plex Sans (`body`, `small`) for everything a person reads.
- IBM Plex Mono (`figure`, `label`) for every number from the books and for uppercase eyebrows. Figures are never set in the sans.

All three load from Google Fonts.

## Layout

Columns are separated by `space-2`, padded `space-3` and rounded `radius-lg`. Sections are `space-4` apart. On narrow screens the columns stack, pharmacy first.

## Voice

Plain and exact, in the owner's words: "the drawer", "the books", "what customers owe". State what the system does and what it prevents ("Stock cannot go below zero"). No exclamation marks, no "revolutionary", no emoji. Features that are not built are labelled "Coming soon".

## Iconography

None yet. Until a set is chosen, use no icons; lists are marked with a short `line` rule or a figure in mono.
