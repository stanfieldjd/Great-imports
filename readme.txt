=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-time Eventbrite URL importer for collecting review candidates.

== Description ==

Great Imports starts with a simple one-time Eventbrite URL import. It accepts a full Eventbrite event detail URL, extracts the numeric Eventbrite event ID, and stores the result as an internal review candidate.

When an Eventbrite private token is configured in WordPress admin, Great Imports tries the Eventbrite API first. It falls back to public Event JSON-LD only when needed. If the source is blocked, unreadable, empty, or missing verified event data, Great Imports records a blocked/unreadable source candidate and fabricates nothing.

The Exploratory Report download exports a sanitized JSON report with plugin state, Events Manager detection, Eventbrite token status without secret values, all tracked Great Imports candidates, source URLs, Eventbrite IDs, fetch methods, status codes, errors, raw Eventbrite API or JSON-LD payloads when captured, and normalized candidate metadata.

Exploratory API mode stores the primary event response plus separate related Eventbrite responses for description, ticket classes, public collections, venue, organizer, and category when the Eventbrite IDs are available.

This version does not schedule recurring imports, does not directly publish Events Manager events, and does not create Events Manager locations.

== Changelog ==

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
