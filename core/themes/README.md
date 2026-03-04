# Theme Model

atoll-core ships only the built-in fallback theme:

- `default`

Official maintained themes are external repositories:

- `atoll-theme-skeleton`
- `atoll-theme-business`
- `atoll-theme-editorial`
- `atoll-theme-portfolio`

A theme is a package of:
- `templates/` (Twig layout/component/page overrides, optional)
- `assets/main.css` (required for visual styling)

Template resolution order:
1. `templates/` (site hard override)
2. `themes/<active>/templates/` (site theme)
3. `core/themes/<active>/templates/` (core theme if present)
4. `core/themes/default/templates/` (fallback)

Asset lookup via `theme_asset('main.css')`:
1. `themes/<active>/assets/main.css`
2. `core/themes/<active>/assets/main.css` (if present)
3. `core/themes/default/assets/main.css`
