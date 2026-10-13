# Homepage hero slider

The slider on the storefront home page is data driven. Slides and settings are
managed in the admin under **Homepage Hero Slider** (`/admin/hero-slides`).

## Files

| Purpose | File |
|---|---|
| Section markup (wrapper, indicators, controls) | `resources/views/storefront/hero/_slider.php` |
| One slide | `resources/views/storefront/hero/_slide.php` |
| Styles and `@font-face` | `resources/views/storefront/hero/_slider_css.php` |
| Behavior (autoplay, keyboard, swipe, tilt) | `public/assets/js/hero-slider.js` |
| Self-hosted fonts and licenses | `public/assets/fonts/` |
| Data, themes, validation, SVG screens | `app/Services/HeroSlideService.php` |
| Admin screens | `resources/views/admin/hero-slides/`, `public/assets/js/admin-hero-*.js` |

If no slide is published, `HomeController` renders the four built-in reference
slides (`HeroSlideService::referenceSlides()`), so the hero never disappears.
These are sample content. Replace them in the admin before launch.

## Settings

Autoplay on/off, interval (3000 to 20000 ms), pause on hover, the announcement
bar items and the maximum slide count are stored in `hero_settings`. They reach
the script as data attributes on `#hero`:

```
data-autoplay="1|0"   data-interval="7000"   data-pause-hover="1|0"
```

With a single live slide the indicators, arrows and autoplay are not rendered.
Visitors who prefer reduced motion get no movement or fades, but autoplay still
runs because the pause button is always visible.

## Rules that keep it working (Content-Security-Policy)

The site sends `script-src 'self'; font-src 'self'; style-src 'self' 'unsafe-inline'`
(`config/security.php`). This has two consequences:

1. **No inline JavaScript.** An inline `<script>` or `onclick=` is silently
   blocked. Put code in `public/assets/js/*.js` and load it with
   `<script type="module" src="...">`. Pass server values through `data-*`
   attributes. An inline script on the home page is what stopped autoplay.
2. **No Google Fonts.** The stylesheet and font files are blocked. Fonts are
   self-hosted (Bricolage Grotesque, Figtree, IBM Plex Mono, SIL OFL 1.1).

## CSS isolation

Every class is prefixed `hs-` and every token (`--bg`, `--ink`, `--accent`, ...)
is declared on `.hs-hero`, not `:root`. The storefront stylesheet defines
generic `.ghost`, `.chip`, `.btn-ghost`, `.specs`, `.eyebrow` and `.wrap`
classes plus its own `--bg`, `--line` and `--muted` variables. Using those names
inside the slider changes its look and, for `:root` variables, changes the rest
of the page. Keep the prefix.

## Admin live preview

`/admin/hero-slides/{id}/preview` is shown in an iframe on the slide editor. It
is the only page that sends `X-Frame-Options: SAMEORIGIN` and
`frame-ancestors 'self'`. Every other response keeps `DENY` and `'none'`.
`SecurityHeadersMiddleware` does not overwrite a header the controller already
set.

## Tests

`php bin/test.php HeroSlide` covers validation, caching, the public API, the
home page markup (no inline script, no Google Fonts, all slides rendered,
autoplay attributes), reorder, and the preview frame headers.
