# Branding assets

The storefront logo lives in `public/assets/img/` and is committed to git.

| File | Use |
| --- | --- |
| `logo-mark.svg` | Square RTP pillar icon (vector trace of the supplied `RTP_Logo_1.jpg`). Used by `resources/views/layouts/_logo.php` in every header and footer, the admin sidebar and the login card. |
| `favicon.svg` | Browser tab icon (same artwork as the mark). |
| `favicon-32.png` | 32px PNG fallback for browsers that ignore SVG favicons. |
| `favicon-192.png` | 192px PNG icon (rounded, transparent corners) for Android / PWA use. |
| `apple-touch-icon.png` | 180px opaque icon used when the site is added to an iOS home screen. |
| `logo-full.svg` | Icon + wordmark, outlined text, for light backgrounds (emails, print, social). |
| `logo-full-light.svg` | Same, for dark backgrounds. |

Do not point templates at `public/uploads/...` for brand assets. `public/uploads` is
gitignored, so those files do not exist on a fresh deploy and the image breaks.

To change the logo, replace the SVGs and re-export the three PNG icons from `logo-mark.svg`. Header and footer pick it up through the shared
`layouts/_logo` partial (`variant` is `light` for the white header, `dark` for the navy footer).
The wordmark is live HTML text, so it stays sharp and selectable.
