# DESIGN.md - Игроскан

## Context (from discovery)

- Artifact type: consumer marketplace search with an analytical decision-support surface.
- Positioning: technical, consumer-friendly, honest about the limits of collected data.
- Audience: people comparing PC game prices before buying. Primary action: decide whether to buy now and choose a trusted offer.
- Adjectives: precise, calm, honest, scannable.
- Visual word translations: precise -> aligned numeric columns and monospace prices; calm -> near-black surfaces and one restrained accent; honest -> explicit observation period and quiet insufficient-data states; scannable -> strong left alignment and thin ruled rows.
- Aesthetic essence (3 words): quiet, technical, exact.
- Single-minded proposition: Игроскан turns noisy marketplace prices into a trustworthy purchase decision.
- References: Stripe Dashboard for calm numeric hierarchy; Linear for compact interaction states; avoid oversized decorative charts and generic rounded-card stacks.
- Mode: dark and light. Density: balanced on search, dense inside price data.
- Constraints: React with plain CSS tokens, Laravel API, WCAG 2.2 AA, mobile support, existing Golos Text / Unbounded / JetBrains Mono identity. While language and currency selectors remain outside the MVP, the interface is fixed to Russian and all customer-facing prices are RUB.

## Aesthetic

- Direction: dark price scanner with editorial restraint.
- Defining trait: information is grouped by alignment and ruled ledgers instead of nested cards.
- Signature move: the price passport, a decision panel paired with a compact numeric ledger.

## Typography

- Display: Unbounded, Google Fonts, OFL.
- Body: Golos Text, Google Fonts, OFL.
- Mono: JetBrains Mono, Google Fonts, OFL; all prices and dense metadata use tabular numerals.
- Scale: ratio 1.25, base 16px. Display 53px/1.08; h1 32px/1.15; h2 26px/1.2; body 16px/1.55; small 13px/1.45; micro 10px/1.35.
- Weights: 400/500/600/700/800. Body measure: 65 to 75ch. Uppercase micro-labels use 0.08em to 0.14em tracking.

## Color

- Strategy: a neutral blue-black scanner surface with green used only for value, focus and success. Source colors identify Steam, Plati and GGsel without controlling hierarchy.
- Distribution: 60 neutral / 30 elevated neutral / 10 semantic accents.
- Palette (role -> OKLCH | hex fallback):
  - bg: oklch(15% 0.018 250) | #0a0e13
  - surface: oklch(20% 0.022 250) | #11171f
  - elevated: oklch(24% 0.028 250) | #1a2431
  - fg: oklch(94% 0.012 245) | #e9eef4
  - muted: oklch(73% 0.035 245) | #9db0c3
  - tertiary: oklch(55% 0.035 245) | #67788c
  - border: oklch(69% 0.035 245 / 0.14) | rgba(146, 168, 190, 0.14)
  - accent: oklch(79% 0.145 155) | #52d992
  - accent-fg: oklch(15% 0.018 250) | #0a0e13
  - success: oklch(78% 0.16 153) | #4cd787
  - warning: oklch(78% 0.14 77) | #f0b24a
  - error: oklch(70% 0.16 25) | #f07070
  - Steam / Plati / GGsel: oklch(72% 0.14 235) / oklch(72% 0.13 300) / oklch(75% 0.14 55).
- Light mode overrides: bg oklch(95% 0.01 250) | #eef1f5; surface oklch(100% 0 0) | #ffffff; fg oklch(20% 0.025 250) | #101a26; accent oklch(58% 0.16 155) | #0d9457.

## Spacing, radius, shadow

- Spacing base: 4px, scale 1/2/3/4/5/6/8/10/12.
- Radius: 8px for controls, 12px for sections.
- Shadow approach: defined edges for cards and data surfaces. Soft elevation is reserved for transient overlays only and never stacked with a strong border.

## Layout and composition

- Grid: modular search-result stack in a 1120px shell. Data panels use a two-column decision/ledger split.
- Spacing rhythm: 4px to 12px within a data group; 20px to 32px between conceptual groups.
- Signature layout move: the public recommendation and its evidence share one uninterrupted price-passport surface.
- Density: balanced, with dense ruled rows for history. Scanning: F-pattern.
- Responsive: desktop-first analytical split; collapse to one column at 760px and remove nested scrolling on narrow screens.

## Components and states

- Button hierarchy: primary filled signal, secondary defined edge, tertiary text. All include hover, active, focus, disabled and loading states.
- Inputs: persistent labels or accessible names; validation is inline and preserves input.
- Tables: text left-aligned, numeric values right-aligned, tabular numerals, thin row separators. Mobile rows reflow instead of shrinking columns.
- Overlays: modal/sheet only for focused tasks such as authentication; focus is trapped and returned by the app shell.
- Empty / loading / error: a quiet inline scanner row replaces the analytical surface. Until coverage is sufficient it shows only collection status and observation volume; the full price passport appears automatically once the threshold is met.
- Focus ring: 2px accent with 2px surface offset.
- Radar condition ledger: the alert modal presents three plain, mutually exclusive conditions: a manually entered RUB threshold, Steam official discount percentage, and a new observed low. The current-price suggestion is provenance text plus an explicit apply action; it never fills a threshold by itself.
- Progressive disclosure: platform and offer-kind controls live in one native `details` element labelled “Дополнительные настройки”. Steam discount keeps its fixed official-Steam scope visible as copy, rather than showing a redundant matrix.
- Candidate selection: ambiguous titles are a ruled, explicit list with artwork, candidate kind, and known stored price. Browser autocomplete merges canonical local matches with Steam's global US discovery plus live RU availability, deduplicates by appid, and may expose up to 20 current-query matches inside one bounded scroll area. Exact normalized titles rank first, followed by title prefixes and whole-phrase matches; changing the query clears the old list immediately. Empty focused search can show up to four appid-backed local recents and makes no discovery request.
- Candidate rows use a fixed artwork rail, left-aligned title/type copy, and a right-aligned honest price state (`Бесплатно`, `Ещё не вышла`, `Недоступно в регионе RU`, or `Цена уточняется`). `Недоступно в регионе RU` is reserved for a completed successful Steam scan; pending, failed and never-scanned states remain `Цена уточняется`. Missing art retries a deterministic Steam capsule before falling back to the neutral rail.
- When Steam RU is unavailable, the resolved game card prioritizes the official Steam US price, displays its stored exchange-rate conversion in RUB, and keeps provenance visible as `Цена Steam США · пересчёт из $ в ₽`. The amber availability badge communicates regional absence; it does not suppress the usable fallback price.
- The desktop profile control ends in a square notched terminal joint. This preserves the intentionally unrounded right edge when the control is no longer flush with the viewport.
- The compact bell opens a persistent notification ledger instead of navigating away. Its badge shows the exact unread count through 9 and `9+` above it, while the accessible name always announces the full count. Opening the ledger advances one monotonic read cursor through the newest visible event; offline game alerts and administration broadcasts remain stored until then. Mobile uses the same ledger as a bottom-anchored sheet above navigation.
- Incoming site notifications use one transient signal: a compact top-right event row with a short two-tone sound after the browser has received user interaction. It disappears after 6.5 seconds without changing unread state. The permanent ledger, badge and textual type labels remain the source of truth.
- Per-game alert management is a single bell-labelled control in each favorite row. Active is signal green with `Уведомления включены`; triggered is amber with `Сигнал сработал` and exposes reactivation inside the existing settings dialog. The removed Active/Triggered two-column ledger is not part of this flow.
- Administration broadcasts use a dense labelled form, textual priority selector, in-place preview and explicit irreversible-send acknowledgement. Broadcast storage snapshots the current registered audience without creating one notification row per user.
- Marketplace ledgers keep one source of truth for the low price: the linked `Дешёвый лот` column. The duplicate unlinked `Минимум` column is omitted. `Популярный` is selected only from marketplace-provided sales counts; missing counts remain unknown instead of appearing as zero sales. Marketplace game cards may mix platforms, so explicitly labelled console and competing PC-store lots are excluded while generic and Steam lots remain eligible.
- Radar conditions form one compact three-position register. Bulk offer-kind controls across marketplaces and per-market select-all actions remain inside the advanced disclosure.
- New-low settings show stored per-scope observation baselines as read-only evidence; there is deliberately no editable price threshold for this condition.

## Motion

- Duration scale: instant 100ms, fast 150ms, normal 220ms, slow 300ms.
- Easing: cubic-bezier(0.22, 0.61, 0.2, 1).
- What animates: transform and opacity only. Reduced motion removes transforms and retains a short opacity state change.
- Signature motion: none; price history is a reading surface.
- Notification motion is causal and isolated: the ledger scales from the bell at 180ms, the incoming signal enters from the right at 220ms, both have matching exits, and reduced-motion users receive opacity-only transitions.

## Iconography

- Set: existing custom React icon set. Grid: 24px, consistent round joins and caps. Small status dots may identify sources, but text labels remain mandatory.

## Imagery and illustration

- Mode: real Steam game artwork and real product data only.
- Rules: imagery supports identification; it never sits behind price text.
- Avoid: stock imagery, decorative AI illustration, gradient blobs and chart ornament.
- Text-over-image contrast: text is not placed over game artwork.

## Dark mode

- Base bg: near-black at about 15% OKLCH lightness; fg is off-white at 94%.
- Elevation ramp: 15% / 20% / 24% lightness. Accent is desaturated enough to remain legible without glow.
- Borders are lighter than their surface and remain subordinate to content.

## Accessibility

- Contrast: target WCAG AA in both modes. Focus is always visible.
- Keyboard: tabs use native buttons, `role=tab`, `aria-selected` and controlled panels. Period uses a native select.
- Search autocomplete supports input-to-list ArrowDown, roving ArrowUp/ArrowDown, Escape back to the input, and a labelled controlled list.
- Targets: 44px preferred for tabs and mobile controls; never below 24px.
- Color independence: source, verdict and price direction always include text, not color alone.
- Reduced motion: supported globally and in the price-history component.

## Tokens (source of truth)

```css
:root {
  --font-display: 'Unbounded', 'Golos Text', sans-serif;
  --font-body: 'Golos Text', sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
  --color-bg: oklch(15% 0.018 250);
  --color-surface: oklch(20% 0.022 250);
  --color-fg: oklch(94% 0.012 245);
  --color-muted: oklch(73% 0.035 245);
  --color-border: oklch(69% 0.035 245 / 0.14);
  --color-accent: oklch(79% 0.145 155);
  --space-unit: 4px;
  --radius-control: 8px;
  --radius-section: 12px;
  --motion-fast: 150ms;
  --motion-normal: 220ms;
  --ease-out: cubic-bezier(0.22, 0.61, 0.2, 1);
}
```

- Adapter: plain CSS custom properties in `frontend/src/app/styles.css`; current legacy aliases map to these semantic roles.

## Cards and surfaces

- Cards/surfaces: defined edge, 8px or 12px radius, relationship-based padding. Avoid cards inside cards; use ruled rows and surface shifts for internal grouping.

## Slop audit

- Date: 2026-08-13. Result: pass after replacing the oversized history chart and E2 card stack with the D3 price passport and E3 ruled change log.
- Notes: numeric hierarchy uses mono/tabular figures, desktop inner scroll is limited to three change rows, mobile uses page flow, authentication does not move the tab, and status meaning remains textual. Final audit added roving tab focus, arrow-key navigation and a 6.64:1 dark-mode contrast token for microcopy. Insufficient coverage now collapses the whole analytical panel into a subdued scanner status instead of exposing empty metrics.
- Radar audit: pass. The condition picker is a ruled ledger with native radio controls; advanced source/type controls remain one collapsed disclosure and candidates retain compact metadata. No gradient, glow, side accent, or layout animation was introduced.
- Search audit (2026-08-14): pass. The existing ruled candidate family was retained; the list gained bounded scrolling and keyboard navigation without adding ornamental surfaces. Pending Steam data is neutral rather than styled as a warning.
- Notification navigation audit (2026-08-18): pass. The control reuses the existing 24px/1.9px icon grid, defined-edge button family and signal token; it has explicit hover, active and focus states, a 36px header target, an accessible name, a labelled mobile navigation target, and no decorative badge that could be mistaken for an unread count. The desktop label was removed after visual review showed excess toolbar density.
- Notification center and broadcast audit (2026-08-20): 9/10 after replacing navigation-only bell behavior with the ruled event ledger and removing the old Active/Triggered card columns. The badge has exact assistive copy and a visual `9+` cap; game, administration, active and triggered meanings remain textual rather than color-only. The transient overlay uses soft elevation without a competing card border, transform/opacity-only motion has matching exits and respects reduced motion, empty/loading states are explicit, and the administration form keeps labels, confirmation and input on error. No nested cards, gradient text, glass, glow, stock imagery or new palette was introduced.
- Notification-to-library guidance audit (2026-08-20): 9/10. MVP notification copy avoids internal “signal” and Telegram terminology; its single settings action opens the existing Favorites ledger. One opacity-only perimeter pulse and a short textual status identify the destination, with a reduced-motion variant and no persistent decorative emphasis.
- Notification density polish (2026-08-20): 9/10. The ledger and transient notice use narrower bounded measures, one-row feeds no longer reserve excess vertical space, and the administration marker shares the circular metadata alignment of other notification types while remaining amber and text-labelled.
- Notification feedback routing (2026-08-20): 9/10. Short action confirmations now enter and leave from the same bounded top-right notification region instead of the bottom edge, while retaining their original copy and polite live-region semantics. The Favorites perimeter guide is request-scoped: it runs only after the notification center settings action and is consumed before later ordinary cabinet visits.
- Notification accumulation audit (2026-08-20): 9/10. The outer center is capped at 620px on desktop and 72vh on mobile; only the labelled ledger scrolls, with contained overscroll, visible keyboard focus and thin native scrollbar. Header, read status and settings remain fixed. The API returns bounded pages and exposes older stored events through an explicit in-ledger “Показать предыдущие” action instead of either growing the panel or silently hiding everything beyond the newest page.
- MVP locale and Steam fallback audit (2026-08-18): pass. Stale EN/non-RUB browser preferences no longer create an invisible mode without controls. Regional unavailability, converted price and source provenance remain separate text signals; the price is not presented as a Steam RU amount and pending scans are not mislabeled as unavailable.
- Search relevance audit (2026-08-18): pass. Region-blocked titles remain discoverable through the global Steam catalog, while RU results retain their live price and availability fields. Exact submit resolution is limited to a normalized title equality and therefore does not silently choose among partial matches.
- Marketplace popularity audit (2026-08-18): pass. Plati card sales are parsed from source markup for every resolved catalog game, unknown sales remain nullable, and the popular slot no longer falls back to the cheapest offer. Explicit console and competing PC-store lots are removed from both marketplace adapters before aggregation. Removing the duplicate minimum column improves scan density without hiding the linked cheapest offer.

## Changelog

- 2026-08-13: documented the existing Игроскан system and approved D3 + E3 price-history family so implementation and future edits share one source of truth.
- 2026-08-13: implemented the price passport and account change log; tightened insufficient-data semantics and passed the responsive/accessibility audit.
- 2026-08-13: collapsed insufficient price history into a compact collection-status row that automatically gives way to D3 when coverage becomes useful.
- 2026-08-13: added the radar condition ledger, explicit suggested-target application, progressive scope disclosure, conservative candidate labels, and local focused-search recents.
- 2026-08-13: clarified the radar ledger’s top-level, non-persistent recommendation boundary and its controlled source disclosure.
- 2026-08-13: tightened search candidates into an aligned price ledger, added honest no-price/release states and artwork recovery, terminated the detached profile edge, restored radar bulk scope controls, and exposed read-only observed-low baselines.
- 2026-08-14: made Steam availability tri-state, removed stale autocomplete caching, expanded discovery to 20 scrollable matches, and added responsive loading plus keyboard navigation.
- 2026-08-18: restored price-alert navigation beside the profile with a compact custom bell icon and a matching mobile Signals destination; removed the desktop label after visual density review.
- 2026-08-18: fixed the selector-free MVP to Russian/RUB and added an explicit Steam RU-unavailable state with a USD-to-RUB official-price fallback.
- 2026-08-18: combined global and RU Steam discovery, ranked exact/prefix/phrase matches, and made the compare action retain an exact autocomplete appid.
- 2026-08-18: restored Plati sales-based popularity across catalog games, preserved unknown sales honestly, removed explicit non-Steam lots from comparisons, and removed the duplicate marketplace minimum column.
- 2026-08-20: added the persistent site notification ledger, unread cursor and `9+` badge, short live signal with sound, per-game alert controls and reactivation, optional Telegram positioning, and guarded administration broadcasts to the current registered audience.
- 2026-08-20: redirected notification settings to the dashboard Favorites ledger with a restrained accessible guide pulse, while keeping the future Telegram page intact but outside the notification-center MVP path.
- 2026-08-20: tightened notification overlay density and optically aligned the administration marker without adding new ornament or motion.
- 2026-08-20: moved transient action feedback into the notification region and made the Favorites guide a one-shot response to notification settings navigation.
- 2026-08-20: bounded large notification histories with an independently scrollable ledger and cursor-based loading of earlier stored events.
