# mavo-img-srcset — Plugin Plan

WordPress plugin that converts simple `<img>` tags with jpgs into responsive `<img>` tags with webp on the fly, without touching the database. Targets the site mamanvoyage.com (GeneratePress free theme, Cache Enabler full-page caching behind Cloudflare, Jetpack optional CDN).

---

## Files

| File | Purpose |
|------|---------|
| `mavo-img-srcset.php` | Plugin logic |
| `mavo-img-srcset.css` | Layout styles for `<img>` and `<figure>` |
| `includes/class-mavo-webp-files.php` | Creates and locates the `.webp` sidecars |
| `includes/class-mavo-webp-cli.php` | `wp mavo-webp` commands (WP-CLI only) |
| `tests/run.sh` | Both suites; no WordPress needed |

## WebP sidecars

This plugin is the only thing on the site that creates `.webp` files. The naming
is an appended extension — `photo-640x480.jpg.webp` — not WordPress's own
`photo-640x480.webp`; tens of thousands of files use it, so it stays.

Nothing re-creates a sidecar after the upload that triggered it, so regenerating
thumbnails, adding an image size or restoring a backup will strand images on
JPEG. That is what the CLI is for:

```
wp mavo-webp status                 # how many sidecars are current / stale / missing
wp mavo-webp sizes                  # what WordPress recorded as intermediate sizes
wp mavo-webp backfill [--dry-run] [--force] [--attachment=<id>] [--limit=<n>]
```

`backfill` is resumable and idempotent; a second run costs stat calls only. A
WebP that is not smaller than its JPEG is discarded rather than written, so the
renderer falls back to the JPEG, which is the cheaper file.

---

## WordPress hooks

Three filters feed into the same `transform()` method, all at **priority 9** (before Jetpack Photon at priority 10, which rewrites image URLs to `i0.wp.com` and would break URL derivation):

| Hook | Covers |
|------|--------|
| `the_content` | Post and page body content |
| `post_thumbnail_html` | Featured images via `get_the_post_thumbnail()` |
| `wp_get_attachment_image` | Featured images rendered directly via `wp_get_attachment_image()` (GeneratePress uses this path) |

---

## transform() — HTML parsing

1. Bail early if the string contains no `<img` (fast path).
2. Wrap content in a full HTML document with a `<div id="mavo-root">` anchor to reliably round-trip through `DOMDocument`.
3. Collect all `<img>` nodes via XPath, process them in **reverse document order** so replacements don't invalidate sibling/parent references.
4. Serialize back by iterating `mavo-root`'s child nodes through `saveHTML()`.

---

## process_img() — per-image transformation

### Skip conditions (leave `<img>` untouched)
- `width` attribute missing or `< 960`
- `src` is empty
- `src` contains `i0.wp.com` or `?` (Jetpack CDN or any query-string URL)
- `src` extension is `.png`

### URL derivation
From the original `src` (e.g. `.../IMG_1987.jpeg`):

| Variant | Formula |
|---------|---------|
| 960w JPEG | original `src` |
| 640w JPEG | `basename-640x{h}.ext` where `h = round(640 × orig_height/orig_width)` |
| 480w JPEG | `basename-480x{h}.ext` |
| for all 3 above: convert to WebP | append `.webp` to each JPEG URL above |

`round`, not truncation — `wp_constrain_dimensions()` rounds, and truncating names
files that do not exist. `basename` drops a trailing `-rotated`: WordPress writes an
EXIF-rotated upload as `IMG_6585-rotated.jpeg` but names its intermediate sizes after
the un-rotated base.

### Every derived URL is checked before it is used

The heights above are computed from the `width`/`height` **attributes**, which are
themselves rounded, so a derivation can land a pixel off the real filename. Each
candidate is therefore resolved to a path under the uploads directory and checked
with `file_exists()`:

- full-size WebP missing → the `<img>` is left exactly as the editor wrote it
- an intermediate missing → that entry is dropped from the `srcset`
- URL not under the uploads directory (CDN, offloaded media) → kept, as before

A sweep of 42 live posts in Sept 2026 found 18 of 1309 image URLs returning 404 for
these two reasons. `tests/run.sh` covers each case, including that an image whose
three files all exist comes out byte-identical to the previous behaviour.

The correct long-term fix is to read `$meta['sizes']` through the `wp-image-NNN`
class rather than deriving filenames at all — the approach Mavo Picture Tag already
uses. That changes the URL of every image on the site, so it is deliberately left
for a separate, verifiable change.

### `<img>` structure produced

```html
  <img src="….jpeg.webp"
       srcset="….jpeg.webp 960w, …-640xH.jpeg.webp 640w, …-480xH.jpeg.webp 480w"
       sizes="(max-width: 960px) 100vw, 960px"
       alt="…" class="…" width="960" height="…" loading="lazy" decoding="async">
```

- The `sizes` value `(max-width: 960px) 100vw, 960px` matches the site's content column max-width.
- Alignment classes (`aligncenter`, `alignleft`, `alignright`, `alignnone`) are dropped and replaced by aligncenter so the theme CSS aligns all images in content.
- All original `alt`, `class`, `width`, `height` attributes are preserved on the inner `<img>`.
- `loading="lazy"` and decoding="async" is added (native browser lazy loading).

### Context detection — centered `<p>` wrapper

If the `<img>` is inside a `<p style="text-align: center;">`, the entire `<p>` is dropped. The centering is handled by the `aligncenter` class on `<img>`.

### Context detection — `<em>` caption

If the `<img>` (or its parent `<p>`) is immediately followed by an `<em>` node (whitespace between is allowed; any other tag or non-whitespace text breaks the match), the output is wrapped in a `<figure>`:

```html
<figure class="wp-picture-figure">
  <img...>
  <figcaption>caption text</figcaption>
</figure>
```

The `<em>` node and any whitespace-only text nodes between it and the image are removed from the DOM. The `wp-picture-figure` class matches existing theme CSS on the site.

---

CSS - add same rules as for img from the theme:

figure.wp-picture-figure            { display: block; margin: auto; text-align: center; }
figure.wp-picture-figure figcaption { font-size: .875em; font-style: italic;
                                      color: #666; margin-top: .5em; margin-bottom: 1em; }
```

---

## Compatibility notes

- **Cache Enabler / Cloudflare**: full-page caching runs after our filters, so the transformed markup is what gets cached. Note that Cloudflare Polish already negotiates WebP at the edge — a request for a `.jpeg` can come back as WebP — which makes part of the `.webp` sidecar machinery redundant. (The plugin emits a plain `<img>`, not `<picture>`; the `wp-picture-figure` class is a leftover name matching existing theme CSS.)
- **Jetpack Photon CDN**: if enabled, rewrites `<img>` URLs at priority 10. Our priority 9 ensures we run first. If a CDN URL somehow reaches `process_img()`, the `?` guard skips it safely.
- **GeneratePress free**: featured image is rendered via `wp_get_attachment_image()` (not `get_the_post_thumbnail()`), hence the dedicated `wp_get_attachment_image` hook.
