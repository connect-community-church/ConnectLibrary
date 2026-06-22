# ConnectLibrary — Shortcodes and Block Variations

Site editors and librarians can embed catalog lists on any WordPress page using shortcodes or Gutenberg blocks. All lists use the same server-rendered book cards and availability status as the main catalog page.

---

## Base catalog shortcode

```
[connectlibrary_catalog]
```

Renders the full public catalog with optional grid/list toggle and pagination.

### Supported parameters

| Parameter          | Default  | Values / Notes |
|--------------------|----------|----------------|
| `view` / `layout`  | `grid`   | `grid` or `list` (`layout` is an alias for `view`) |
| `per_page` / `limit` | `12`   | 1–50; `limit` is an alias for `per_page` |
| `sort`             | `title`  | `title` \| `author` \| `newest` \| `availability` |
| `category`         | —        | Category slug (e.g. `bible-studies`) |
| `tag`              | —        | Tag slug |
| `age_level`        | —        | Age/reading level slug (e.g. `children`, `youth`) |
| `availability`     | —        | Availability status slug |
| `author`           | —        | Author slug from the custom authors table |
| `series`           | —        | Series slug from the custom series table |
| `search`           | —        | Keyword pre-filter (for embed use) |
| `show_view_toggle` | `true`   | `true` or `false` — show/hide grid–list toggle buttons |
| `show_filters`     | `true`   | `true` or `false` — show/hide the GET filter form; when `true`, renders search, category, tag, age level, availability, author, series, and sort controls above the book list |
| `show_search`      | `false`  | Accepted and stored; the search field is included in the filter form whenever `show_filters` is `true` and is not independently toggled by this parameter |
| `title`            | —        | Optional heading (`<h2>`) displayed above the catalog |
| `empty_message`    | —        | Override the empty-state text (default: "No books found.") |

Unsupported attributes are silently ignored. All values are sanitized before use; arbitrary SQL/meta queries are not possible through these parameters.

### Examples

Full catalog, grid layout, title sorted:

```
[connectlibrary_catalog]
```

List layout, newest first:

```
[connectlibrary_catalog layout="list" sort="newest"]
```

Limit to 6 books in a sidebar:

```
[connectlibrary_catalog limit="6" show_view_toggle="false"]
```

Pre-filtered to a category with a heading:

```
[connectlibrary_catalog category="fiction" title="Fiction" limit="8" show_view_toggle="false"]
```

---

## Preset shortcodes

Preset shortcodes are aliases with sensible defaults for common use cases. All parameters from the base shortcode are still accepted and will override the preset defaults.

### New Arrivals

```
[connectlibrary_new_arrivals]
```

Defaults: `sort=newest`, `limit=6`, `show_view_toggle=false`.

```
[connectlibrary_new_arrivals limit="8"]
```

### Featured / Recommended Books

```
[connectlibrary_featured_books]
```

Filters by **category slug `featured`**. Add books to the WordPress category named "Featured" (slug: `featured`) to make them appear here. When no books match, shows a friendly empty state and no error.

Override the category slug:

```
[connectlibrary_featured_books category="recommended"]
```

### Category List

```
[connectlibrary_category_books category="bible-studies"]
```

Filters by a category slug. The `category` attribute is the primary parameter.

Common examples:

```
[connectlibrary_category_books category="kids" title="Kids Books" limit="6"]
[connectlibrary_category_books category="devotional" title="Devotionals"]
[connectlibrary_category_books category="biography" sort="author"]
```

### Author Books

```
[connectlibrary_author_books author="c-s-lewis"]
```

Filters by author slug from the custom author records. Use the slug shown in the book edit screen.

### Series Books

```
[connectlibrary_series_books series="chronicles-of-narnia"]
```

Filters by series slug from the custom series records.

---

## Gutenberg block variations

In the block editor, insert the **ConnectLibrary Catalog** block. In the block inserter search for "ConnectLibrary" — on WordPress 6.7+ you will see these preset variations listed directly:

| Variation        | Pre-set defaults |
|------------------|-----------------|
| New Arrivals     | sort: newest, limit: 6, toggle off |
| Featured Books   | category: featured, limit: 6, toggle off |
| Category List    | limit: 6, toggle off |
| Kids / Youth     | age_level: children, limit: 6, toggle off |
| Bible Studies    | category: bible-studies, limit: 6, toggle off |

After inserting a variation, use the block sidebar to adjust any attribute (category slug, limit, sort, etc.). The frontend always renders server-side using the same PHP renderer as shortcodes.

If block variations are not visible (WordPress < 6.7), insert the plain ConnectLibrary Catalog block and set attributes in the sidebar, or use a shortcode instead.

---

## Availability and privacy

- Hidden/private books are never shown in any list or embed.
- No borrower names, reservation history, or librarian-only notes appear in public output.
- The `availability` filter accepts the same public status slugs shown on book cards (e.g. `available`, `checked-out`).

---

## CSS classes

All catalog lists share the same CSS class namespace for consistent theming:

| Class | Purpose |
|-------|---------|
| `.connectlibrary-catalog` | Outer wrapper (all lists) |
| `.connectlibrary-catalog__heading` | Optional `<h2>` title |
| `.connectlibrary-catalog__items.is-grid` | Grid layout container |
| `.connectlibrary-catalog__items.is-list` | List layout container |
| `.connectlibrary-catalog__book` | Individual book card (`<article>`) |
| `.connectlibrary-catalog__empty` | Empty-state paragraph |
| `.connectlibrary-catalog__pagination` | Pagination `<nav>` |
| `.connectlibrary-catalog__toggle` | Grid/list toggle buttons |
