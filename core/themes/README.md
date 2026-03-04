# Built-in Themes

atoll-core ships multiple built-in themes.

- `default`: balanced starter theme
- `business`: corporate/service sites
- `editorial`: content-heavy docs/blogs
- `portfolio`: visual showcase/agency style

A theme is a package of:
- `templates/` (Twig layout/component/page overrides, optional)
- `assets/main.css` (required for visual styling)

Template resolution order:
1. `templates/` (site hard override)
2. `themes/<active>/templates/` (site theme)
3. `core/themes/<active>/templates/` (core built-in)
4. `core/themes/default/templates/` (fallback)

Asset lookup via `theme_asset('main.css')`:
1. `themes/<active>/assets/main.css`
2. `core/themes/<active>/assets/main.css`
3. `core/themes/default/assets/main.css`
