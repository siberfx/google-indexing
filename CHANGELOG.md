# Changelog

## 1.0.2

- Fix: the Search Console sitemap submission sent a JSON `[]` body, which Google rejects (`Invalid JSON payload received. Unknown name "": Root element must be a message`). It now sends no body.

## 1.0.1

- Fix: an unusable `private_key` now throws `GoogleIndexingException` instead of a PHP warning.
- Tests for the token provider, `SubmissionResult` and the `indexing:publish|status|install` commands; GitHub Actions matrix (PHP 8.4/8.5 × Laravel 12/13).

## 1.0.0

- Google Indexing API engine (`URL_UPDATED` / `URL_DELETED`, concurrent, per-URL errors, metadata lookup).
- Search Console sitemap submission.
- IndexNow engine with auto-served key file.
- Sitemap reader: indexes, gzip, local files, `<lastmod>` filter.
- `Indexable` Eloquent trait, queued unique job, scheduler auto-registration.
- Artisan: `indexing:install`, `indexing:sitemap`, `indexing:publish`, `indexing:status`.
