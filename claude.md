# mavo-img-srcset — Plugin Plan

WordPress plugin that converts simple `<img>` tags with jpgs into responsive `<img>` tags with webp on the fly, without touching the database. Targets the site mamanvoyage.com (GeneratePress free theme, Swift Performance caching, Jetpack optional CDN).

---

## Files

| File | Purpose |
|------|---------|
| `mavo-img-srcset.php` | Plugin logic |
| `mavo-img-srcset.css` | Layout styles for `<img>` and `<figure>` |

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

- **Swift Performance**: runs after our filters and converts `srcset` → `data-srcset` on `<source>` elements for its JS lazy loader. The `<picture>/<source>` structure is preserved intact. Compatible.
- **Jetpack Photon CDN**: if enabled, rewrites `<img>` URLs at priority 10. Our priority 9 ensures we run first. If a CDN URL somehow reaches `process_img()`, the `?` guard skips it safely.
- **GeneratePress free**: featured image is rendered via `wp_get_attachment_image()` (not `get_the_post_thumbnail()`), hence the dedicated `wp_get_attachment_image` hook.
