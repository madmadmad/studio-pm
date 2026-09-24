# Styles

All styling is Sass, written as semantic BEM classes. There is no utility framework. Vite compiles one entry, `resources/scss/app.scss`, with `sass-embedded`. `/style-guide` renders the shared components live, so check it after changing one.

## Structure

```
resources/scss/
  app.scss        the only entry; loads each folder into a cascade layer
  abstracts/      Sass-only helpers, emit no CSS: breakpoints, mixins
  base/           tokens, fonts, reset, element defaults, keyframes, u- helpers
  layout/         page structure: app/portal/auth shells, page header,
                  page sections, form grid, toolbar, cluster...
  components/     the shared UI vocabulary: btn, card, input, table,
                  modal, drawer, invoice-form...
  pages/          styles that belong to exactly one page
```

`app.scss` owns the cascade. Later layers win, whatever the selector specificity:

```
reset → base → layout → components → pages → helpers
```

Partials never declare `@layer` themselves. To add a partial, `@forward` it from its folder's `_index.scss`.

Order in `components/_index.scss` matters. Primitives (btn, input...) come before composites. When a composite's element is mixed onto a primitive, as in `input send-invoice__trigger`, both selectors have equal weight, so the later composite wins.

## Tokens

Every design value is a custom property on `:root`, defined in `base/_tokens.scss`:

| Group | Examples | Notes |
|---|---|---|
| Color | `--color-gunmetal`, `--color-watermelon-soft`, `--color-scrim` | Brand palette names, plus on-dark text, scrims and hover shades |
| Shell color | `--color-canvas`, `--color-panel` | The dark frame and the light surface each page sits in |
| Type | `--font-size-sm` + `--line-height-sm`, `--font-weight-semibold` | Sizes come in pairs; set both |
| Spacing | `--space-1` … `--space-16` | Step numbers follow the old 4px scale (`--space-3` = 0.75rem) |
| Semantic spacing | `--space-panel`, `--space-section`, `--space-page` | Reach for these first |
| Radius | `--radius-base`, `--radius-pill` | One knob for all component corner rounding |
| Shell radius | `--radius-panel`, `--radius-drawer` | Shell layers only; components never use these |
| Elevation | `--shadow-popover`, `--shadow-overlay`, `--shadow-drawer`, `--z-overlay` | |
| Motion | `--duration-fast`, `--ease-standard`, `--ease-enter`, `--ease-exit` | |
| Shell motion | `--duration-panel`, `--duration-drawer`, `--motion-panel-offset` | Page-change slide and drawer slide in/out; enter with `--ease-enter`, exit with `--ease-exit` |
| Sizes | `--size-sidebar`, `--size-drawer`, `--size-form` | Recurring container widths |

Breakpoints can't be custom properties, because `var()` doesn't work in media queries. They live in a Sass map in `abstracts/_breakpoints.scss` (`sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem):

```scss
@use '../abstracts' as *;

.thing {
    @include respond-to('md') { … }
}
```

## Conventions

- **BEM.** Use `.block`, `.block__element` and `.block--modifier`. Modifiers go alongside the base class: `class="btn btn--primary"`.
- **One block per partial**, and the file name matches it: `.drawer` lives in `components/_drawer.scss`.
- **No magic numbers.** Values come from tokens. A true one-off gets a short comment saying why (`width: 7rem; // fits a five-figure amount`). Keywords like `100%` and `100vh` are fine.
- **Local custom properties** are fine for a block's own geometry, declared at the top of the block with a comment (see `_toggle.scss`).
- **Nesting depth of 3 at most.** No ID selectors. No `!important`; the one exception is the `[hidden]` rule in the reset.
- **Modules:** `@use` and `@forward` only, never `@import`. Partials that need mixins start with `@use '../abstracts' as *;`.
- **Page styles:** add a `pages/` partial only when a style truly belongs to one page. If a second page needs it, promote it to `components/`.
- **Helpers:** a deliberately tiny set (`u-sr-only`, `u-tabular-nums`, `u-truncate`). Don't grow it; give the element a BEM class instead.

## In React

- **Shared React components own their block:** `Button` → `.btn` / `.link-btn`, `Badge` → `.badge`, `Drawer` → `.drawer`, `PageHeader` → `.page-header`, `AuthLayout` → `.auth-shell`, and so on.
- **Class names built at runtime** must only produce modifiers that exist: `badge--${tone}`, `icon-btn--${variant}`. Guard unknown values with a fallback, as `Badge.jsx` does.
- **Hidden elements:** use the `hidden` attribute, not a class.
- **State:** toggle a modifier (`tabs__tab--active`, `subtask-list__item--dragging`) rather than swapping style classes.

## Adding a component

1. Create `components/_thing.scss` with a short header comment saying what it is and where it's used.
2. Build it from tokens, and use `@include` for shared mixins (`focus-ring`, `truncate`, `stack`).
3. `@forward 'thing';` in `components/_index.scss`. Primitives go in the top group, composites below.
4. If it's reusable, add an example to `Pages/Dev/StyleGuide.jsx`.
5. Run `npm run build` and check the result has no errors and no Sass deprecation warnings.
