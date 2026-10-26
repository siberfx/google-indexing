# Changelog

## 1.0.0

- Google Indexing API engine (`URL_UPDATED` / `URL_DELETED`, concurrent, per-URL errors, metadata lookup).
- Search Console sitemap submission.
- IndexNow engine with auto-served key file.
- Sitemap reader: indexes, gzip, local files, `<lastmod>` filter.
- `Indexable` Eloquent trait, queued unique job, scheduler auto-registration.
- Artisan: `indexing:install`, `indexing:sitemap`, `indexing:publish`, `indexing:status`.
