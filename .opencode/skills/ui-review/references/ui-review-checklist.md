# UI Review Checklist

Apply this checklist to every screenshot. Report findings per image, referencing
the file name. Keep it factual: name the element and the visual defect; propose
a concrete fix.

## 1. Contrast & readability

- Text is readable against its background (no low-contrast gray-on-gray for body
  text; small/muted text especially).
- No text on busy/noisy backgrounds; badge/label colors keep sufficient contrast.
- Link color is distinguishable from body text.

## 2. Spacing & alignment

- Consistent spacing rhythm (gaps, paddings, margins) within and between sections.
- Elements align on a grid; no arbitrary offsets or ragged edges.
- No cramped or overflowing content; card/table content has comfortable padding.
- Fixed/sticky elements do not overlap content.

## 3. States (empty / loading / error)

- Empty states render deliberately — a clear empty message, not a blank void or a
  leftover spinner.
- Loading states exist and look intentional (spinner alignment/placement).
- Error states are styled as alerts and readable.
- No "flash" artifacts that would confuse a reviewer.

## 4. Responsive behavior (desktop vs mobile)

- Desktop layout is airy; mobile layout uses the full width without horizontal
  scroll.
- Tables/grids degrade gracefully on mobile (wrap, stack, or scroll within a
  container — never clip).
- Header/nav/sidebar behavior on mobile is usable (menu not overflowing).
- Tap targets are large enough on mobile.

## 5. Hierarchy & typography

- One clear `h1` per page; section headings are visually distinct.
- Font sizes/weights express hierarchy; type scale is consistent.
- No orphan headings or oversized/undersized text.

## 6. Interactive states

- Primary actions are visually dominant; secondary actions are clearly secondary.
- Disabled buttons look disabled (not just dimmed text).
- Hover/focus affordances exist (focus ring visible).
- Buttons/links are labelled; icons alone are not (unless accompanied by an
  accessible name).

## 7. i18n correctness

- UI copy is in the active language (no untranslated or English strings leaking
  into a German UI and vice versa).
- No hard-coded user-visible strings that should be catalogued.
- Date/number formatting matches the locale.

## 8. Design system consistency

- Components use the design system's primitives (this repo: daisyUI classes —
  `btn`, `card`, `badge`, `alert`, `table`, `menu`, etc.); no hand-rolled
  look-alikes.
- Colors come from the theme palette, not ad-hoc hex values.
- Consistent border radius, shadows, and hover states across components.

## 9. Layout bugs / overflow

- No horizontal overflow, clipped text, or content cut off at viewport edges.
- Long words/emails/URLs wrap or truncate instead of breaking the layout.
- Images scale correctly (no distortion); missing images hide gracefully.

## 10. Branding

- Logo/header/footer of the tenant/app are present and correct.
- No missing/duplicate logo or header on any state.
- Brand assets are not pixelated or stretched; fallback branding is coherent.
