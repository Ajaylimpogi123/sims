# SIMS — aligning the mobile app with the website

**This file overrides the generic guidance in this skill wherever they conflict.** The SIMS app (`C:\xampp\htdocs\sims-mobile`, Expo + NativeWind) must look like the same product as the SIMS website (`C:\xampp\htdocs\sims`, Laravel + Inertia + React + shadcn/ui `new-york`, Tailwind). Someone who uses both should recognise the colours, status badges, wording and layout of each feature.

Sources on the web side (read these when in doubt; they win over this file if they change):
- `tailwind.config.js` and `resources/css/app.css` — shadcn CSS variables (neutral base), `--radius: 0.625rem`, Figtree font
- `resources/js/Components/StatusBadge.jsx` — the status → colour map
- `resources/js/Components/ui/*` — shadcn primitives (button, card, badge, input, dialog…)
- `resources/js/Pages/Auth/Login.jsx` — BCC login styling (dark green-black background, BCC green + gold)
- `resources/js/Pages/<Feature>/...` — the screen to mirror for each module

## Stack decisions (already made — don't re-decide)

- **Styling:** NativeWind `className` only, locked for the whole app (the skill's "one system per app" rule). Shared primitives live in `src/components/ui` (`Button`, `Card`, `TextField`, `Banner`); extend them instead of styling ad hoc. Raw colour values only in `src/theme/colors.ts`, for props that can't take a class (tab bar, header, spinner, status bar).
- **Navigation:** Expo Router; role visibility only through `src/navigation/access.ts`.
- **Data:** TanStack Query + axios client in `src/api`. The server is the authority on every rule.
- **Skip intake:** the brief is fixed by `docs/MOBILE-APP-ROADMAP.md` and the matching web page. Don't run the skill's requirements-intake questions.
- **Skip `locale-uz.md`.** Locale is English (Philippines): dates and times in **Asia/Manila**, times in **12-hour** format (`8:05 AM`), dates like `Oct 2, 2026`. There's no currency.
- **Maturity:** Stage 1 (a real product for one school, not an MVP demo and not enterprise). No onboarding carousel, no glassmorphism, no Skia signature visuals, no gradient meshes.
- **Light mode only for now.** The website has no dark mode and `app.json` sets `userInterfaceStyle: "light"`. This overrides the skill's "always ship light and dark" rule. Keep colours in tokens so dark mode can be added later.

## Colour

| Role | Web | Mobile |
|---|---|---|
| Brand / primary action, active tab, links | BCC green (`green-700` `#15803d`; login uses `#1F7A3D`) | `brand-700` (`#15803d`), pressed `brand-800` |
| Brand highlight (sparingly: logo area, one emphasis) | gold `#F5B301` on the login page | same gold, never for body text or buttons |
| Text | `gray-900` / `gray-800` headings, `gray-600` secondary, muted `hsl(0 0% 55.6%)` | `gray-900`, `gray-600`, `gray-500` — **use `gray`, not `slate`**, to match the web's neutral palette |
| Surfaces | white cards on white/`gray-50`, borders `hsl(0 0% 92.2%)` (≈ `gray-200`) | `bg-gray-50` screen, `bg-white` cards, `border-gray-200` |
| Destructive | `red-600` / `red-700` text, `bg-red-50 border-red-200` alerts | same |
| Success message | `border-green-200 bg-green-50 text-green-700` | same (the `Banner` component) |
| Warning | `border-amber-200 bg-amber-50 text-amber-800` | same |

One accent (green). Don't add new brand colours, gradients, or per-screen palettes.

### Status badges — copy the web map exactly

Pill: `rounded-full px-2 py-0.5 text-xs font-medium`, label in Title Case.

| Status | Classes |
|---|---|
| active, completed, reviewed, approved | `bg-green-100 text-green-700` |
| pending | `bg-amber-100 text-amber-700` |
| ongoing, submitted | `bg-blue-100 text-blue-700` |
| locked | `bg-purple-100 text-purple-700` |
| rejected | `bg-red-100 text-red-700` |
| inactive, not_started, draft | `bg-gray-100 text-gray-600` |

Build one `StatusBadge` component in `src/components/ui` from this table; never colour a status inline. If the web map changes, change the mobile one to match.

### Charts (dashboards, Module 5)

Use the web dashboard's colours (`AttendanceCharts.jsx`, `EvaluationCharts.jsx` and siblings under `resources/js/Pages/Dashboard` / `Analytics`): present/locked/positive `hsl(160 84% 39%)`, rejected `hsl(0 84% 60%)`, submitted `hsl(217 91% 60%)`, draft/neutral `hsl(220 9% 60%)`, average rating `hsl(262 83% 58%)`. The same series must be the same colour on web and app.

## Type

- Font: **Figtree** (web: `font-sans` = Figtree 400/500/600). Loaded via `@expo-google-fonts/figtree` (done 2026-10-07, sims-mobile 7248f15). `font-normal/medium/semibold/bold` map to the Figtree family names (no `fontWeight` on Android). Always import `Text`/`TextInput` from `src/components/ui/Text.tsx`, never from react-native, or the screen gets the system font. Header/tab/chart fonts come from `src/theme/fonts.ts`.
- Weights 400 / 500 / 600 only (what the web loads). No 700+ except the BCC wordmark.
- Sizes: page title `text-xl font-semibold`, section/card title `text-base font-semibold`, body `text-sm`/`text-base`, meta and badges `text-xs`. Two or three sizes per screen.
- Numbers (hours, counts, KPIs, ratings): tabular figures (`fontVariant: ['tabular-nums']`).

## Shape & depth

- Radius = the web shadcn scale (`--radius` 10px): `rounded-sm` 6px, `rounded-md` 8px, `rounded-lg` 10px, `rounded-xl` 12px — same class = same size as the web. Cards and card-style rows `rounded-xl` (web Card), buttons/inputs/selects/banners `rounded-md` (web Button/Input), inner panels/photos `rounded-lg`, badges and filter chips `rounded-full`, bottom sheets `rounded-t-xl`.
- Elevation is light: white card + `border border-gray-200` + at most a soft shadow (web uses `shadow-sm`/`shadow`). No heavy drop shadows, no glass.

## Components — mirror the shadcn ones

| Web (shadcn) | Mobile equivalent |
|---|---|
| `Button` default/outline/ghost/destructive | `Button` variants: primary (brand green), outline (white + gray border), ghost, destructive (red) |
| `Card` with header/title/description | `Card` with title + optional description, `p-4` |
| `Badge` / `StatusBadge` | `StatusBadge` (table above) |
| `Input` + label + error text | `TextField` with label above, red error below (422 field errors from the API) |
| `Dialog` / `AlertDialog` confirm | native `Alert.alert` for simple confirms; bottom sheet for forms with several fields |
| Data table with search, filters, pagination | `FlatList`/FlashList of cards, search field on top, filter chips, infinite scroll; same columns become card rows |
| Toast / flash messages | `Banner` at the top of the screen, same success/error wording as the web flash message |

## Content & wording

- Use the **same labels as the website**: page titles, menu names (e.g. "Pending Approvals", "Attendance Monitoring", "Report Reviews", "My Feedback"), button text, status names and empty-state messages. Check the matching `resources/js/Pages/...` file before writing copy.
- Same field order in forms as the web form, same validation messages (they come from the API).
- Icons: the web uses **lucide**. Use `lucide-react-native` if it's added to the app; otherwise pick the closest `@expo/vector-icons` glyph to the lucide icon the web page uses, consistently.

## Platform adaptation (Android first)

Keep the web's look, adapted to the phone, not a copy of desktop:
- Bottom tabs instead of the web sidebar; "More" list instead of the sidebar overflow.
- Primary action at the bottom of the screen (thumb zone) instead of the page header.
- Touch targets ≥ 48dp, safe areas respected, pull-to-refresh on every list and dashboard.
- Every data screen has loading (skeleton), empty (web's empty-state text), error with Retry, and offline states.

## Process for SIMS (lighter than the full skill)

For each screen: read the matching web page → plan block (one short paragraph) → build with existing `src/components/ui` primitives → restraint + accessibility pass (`ui-restraint-and-accessibility.md`) → `npx tsc --noEmit` and `npx expo-doctor` clean. The full critique/scorecard (`design-critique-engine.md`, `premium-scorecard.md`) is optional; use it for the main screens of a module (dashboard, attendance capture, approvals), not every form. Report what still needs a real-phone check.
