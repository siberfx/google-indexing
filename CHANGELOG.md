# Changelog

## 1.1.0

### Added

- `indexing:doctor` checks the setup in one go (PHP, credentials, IndexNow key, sitemap, history table). `--live` also calls Google, checks Search Console ownership, fetches the IndexNow key file and the sitemap.
- `indexing:inspect {url}` and `GoogleIndexing::inspect($url)`: whether Google has actually indexed a page (Search Console URL Inspection). Unlike `status()`, this works for any page.
- Optional submission history: set `GOOGLE_INDEXING_HISTORY=true`, publish the migration with `vendor:publish --tag=google-indexing-migrations`, and every engine result is stored.
- `indexing:report --hours=24` summarises submissions per engine and lists the latest failures.
- Bing Webmaster Tools engine (`bing`, key in `BING_WEBMASTER_API_KEY`). Opt-in: add it to `GOOGLE_INDEXING_ENGINES`. Bing cannot remove URLs, so `URL_DELETED` only reports a message.
- Sitemap dedupe (`sitemap.dedupe`, on by default): `submitSitemap()` skips URLs already submitted that have not changed since, and resends them after `sitemap.resubmit_after` (30 days by default).
- `SubmissionResult::$retryable` and `SubmissionResult::$deferred`.
- `http.max_backoff`: a 429 `Retry-After` is honoured, capped at this many seconds.

### Changed

- The Google daily quota (`daily_limit`) is counted across runs, reset at midnight Pacific time. URLs over the limit are **deferred** to a later run, not reported as failed.
- `SubmitUrlsJob` retries only the URLs that failed temporarily (429, 5xx, network). Accepted and permanently rejected URLs are not sent again. The retry is a new job with the next `backoff` delay; the last attempt throws. A batch where every URL was rejected permanently no longer throws, because retrying it would not help.
- `SubmissionResult::summary()` includes `deferred`.
- `UrlsSubmitted` now also fires for the Search Console sitemap submission (`google-sitemap`).

### Fixed

- One engine that throws (bad credentials, token error) no longer stops the others. Its URLs are reported as failed.
- IndexNow connection errors are reported per URL and marked retryable, instead of crashing the submission.

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
