=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full evidence-first Eventbrite importer for collecting review candidates.

== Description ==

Great Imports now uses a full-view-first evidence capture model. It accepts a full Eventbrite event detail URL, extracts the numeric Eventbrite event ID, captures raw source evidence into a dedicated evidence record, and only then creates or updates an internal review candidate.

When an Eventbrite private token is configured in WordPress admin, Great Imports captures Eventbrite API evidence and public page evidence. It also captures the public Eventbrite page response and broad HTML-derived evidence such as meta tags, title tags, links, images, canonical URLs, JSON-LD blocks, and script blocks.

The Exploratory Report download exports a sanitized JSON report with plugin state, Events Manager detection, Eventbrite token status without secret values, evidence records, evidence bundles, all tracked Great Imports candidates, source URLs, Eventbrite IDs, fetch methods, status codes, errors, raw Eventbrite API payloads, raw public-page body evidence, extracted HTML evidence, and normalized candidate metadata.

Candidate data is a downstream interpretation of evidence, not the evidence source. Relevance decisions, normalization, filtering, mapping, and handoff happen after evidence capture.

This version does not schedule recurring imports, does not directly publish Events Manager events, and does not create Events Manager locations.

== Changelog ==

= 0.2.0 =
* Added full-view-first evidence capture foundation.
* Added private `gi_evidence` records for raw source evidence bundles.
* Added raw HTTP response capture including headers, body, hashes, byte counts, content type, status, and timing.
* Added broad HTML evidence extraction for meta tags, titles, links, images, canonical URLs, JSON-LD blocks, and script blocks.
* Connected Eventbrite imports to evidence bundles before candidate normalization.
* Added evidence records and bundles to the Exploratory Report.

= 0.1.3 =
* Expanded exploratory API capture beyond selected candidate fields.
* Added related raw Eventbrite API payload capture for ticket classes, public collections, venue, organizer, and category when available.
* Preserved normalized candidate fields while keeping raw exploratory evidence for later review.

= 0.1.2 =
* Added Exploratory Report download.
* Added all tracked candidate metadata to the report.
* Added raw captured Eventbrite API, description, JSON-LD, and error payload coverage where available.
* Confirmed secret/token values are redacted from reports.

= 0.1.1 =
* Added masked Eventbrite private token setting.
* Added Eventbrite event ID extraction from full event URLs.
* Added authenticated Eventbrite API fetch path with public JSON-LD fallback.
* Added blocked/unreadable source candidate recording.

= 0.1.0 =
* Initial modular WordPress plugin structure.
* Added one-time Eventbrite URL collection.
* Added internal review candidate storage.
