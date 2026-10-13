# Branding assets

The storefront logo lives in `public/assets/img/` and is committed to git.

| File | Use |
| --- | --- |
| `logo-mark.svg` | Square icon. Used by `resources/views/layouts/_logo.php` in every header and footer. |
| `favicon.svg` | Browser tab icon (same artwork as the mark). |
| `logo-full.svg` | Icon + wordmark, outlined text, for light backgrounds (emails, print, social). |
| `logo-full-light.svg` | Same, for dark backgrounds. |

Do not point templates at `public/uploads/...` for brand assets. `public/uploads` is
gitignored, so those files do not exist on a fresh deploy and the image breaks.

To change the logo, edit the SVGs. Header and footer pick it up through the shared
`layouts/_logo` partial (`variant` is `light` for the white header, `dark` for the navy footer).
The wordmark is live HTML text, so it stays sharp and selectable.
