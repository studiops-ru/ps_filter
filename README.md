**English** · [Русский](README.ru.md)

# PS Filter: product filter

Free open-source module (MIT). Product filter for category pages, manufacturer pages and search: facets by price,
manufacturer, attributes, options, stock status and custom fields, results without page reload, product counts next
to every value, a mobile drawer, SEO-friendly filter landing pages and SEO templates, and a sitemap of landing pages.
It runs on its own index in the database, so it stays fast on catalogs with tens of thousands of products; very large
catalogs can use the Manticore search server.

## Features

- **Facets:** price (slider and inputs), manufacturer, attributes, options, stock status, specials, custom product
  facets. Values show product counts; unavailable values are hidden or greyed out. Automatic facet selection per
  category.
- **Results without reload:** products, pagination, sorting and "Found N" update in place, the page address changes,
  Back and Forward work. Without JavaScript the filter works with plain links.
- **Mobile:** the panel opens in a drawer, a "Filter (N)" button, "Show N products" at the bottom.
- **"All filters":** rarely used facets go under a button — as a list, a layer or a dialog.
- **"Popular" sorting:** by sales over a period or by views.
- **Grouping** by model, SKU or another field: sizes of one product are shown as one card.
- **SEO:** landing pages with friendly URLs, their own title, H1, description and text; SEO templates that create
  landing pages for the combinations you need; other filter URLs are closed from indexing with canonical to the
  category; a sitemap of landing pages (and a provider for the PS Sitemap module).
- **Storefront accessibility:** WCAG 2.2 AA — keyboard control, visible focus, announced results, large touch
  targets on phones.
- **Styling in the theme:** CSS variables `--psf-*`, classes, `psf:*` events, templates that can be overridden in the
  theme folder.

## Compatibility

OpenCart 3.0.x / 4.x.

- OpenCart 3.0.x — archive `ps_filter-v<version>-oc3.ocmod.zip`, PHP 7.4–8.4.
- OpenCart 4.x (tested on 4.1.0.4) — archive `ps_filter.ocmod.zip` (do not rename it: OpenCart 4 takes the extension
  code from the file name), PHP 8.1–8.5. Same features.
- MySQL 5.7+ or MariaDB 10.3+.

## Installation

### OpenCart 3

1. Extensions → Installer — upload `ps_filter-v<version>-oc3.ocmod.zip` (no need to refresh modifications: the module
   works through events).
2. Extensions → Modules — click Install next to "PS Filter: product filter". The module creates its tables, events
   and permissions; the menu item is Catalog → PS Filter.
3. Catalog → PS Filter → Indexing — run a full reindex.
4. Design → Layouts — add the "PS Filter" module to a column of the Category, Manufacturer and Search layouts (or
   output the panel in the theme template with the `{{ ps_filter }}` variable).

To update on OpenCart 3, upload the new archive over the old one: module data is kept and the schema updates itself
on the next visit to the admin panel.

### OpenCart 4

1. Extensions → Installer — upload `ps_filter.ocmod.zip` and click Install.
2. Extensions → Modules — click Install next to "PS Filter: product filter"; the menu item is Catalog → PS Filter.
3. Catalog → PS Filter → Indexing — run a full reindex; Design → Layouts — add the module to a column of the
   Category and Search layouts. To show the panel on manufacturer pages too, add the route
   `product/manufacturer.info` to the Manufacturer layout (in OpenCart 4.1.0.4 that layout is bound to
   `product/manufacturer` only).

**Updating on OpenCart 4.** OpenCart 4 does not install a package over an installed one, so: Extensions → Modules —
uninstall PS Filter, Extensions → Installer — uninstall the package, upload and install the new one, then install the
module again. Facets, landing pages, SEO templates, the index, settings and panel positions in layouts are kept
(the "Keep data when the module is uninstalled" setting, on by default).

## Configuration

- Catalog → PS Filter → Settings: panel look, drawer, auto-apply, SEO, "Popular" sorting, grouping, stock.
- Facets: which facets to show and where, order, widget (checkboxes, slider, colors, images).
- Landing pages and SEO templates: filter pages for search engines.
- Indexing: the cron key and tasks (`reconcile` hourly, `prices` and `warm` at night, `gc` daily), full reindex.

## Uninstalling

Extensions → Modules → PS Filter → Uninstall. On OpenCart 3 (and on OpenCart 4 with "Keep data when the module is
uninstalled" turned off) the module removes its tables (index, facets, landing pages, SEO templates, cache), events,
permissions, settings and layout positions; the Manticore table, if any, is removed too. Store products, attributes
and options are not changed.

## Support

Questions and bug reports — info@studiops.ru (the Support tab in the module settings has a button that copies site
details for the email). All studio modules — https://studiops.ru/modules/

## License

MIT, © 2026 Studio of Sergey Polyakov (sole proprietor S. V. Polyakov), studiops.ru. See `LICENSE`.
