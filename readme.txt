=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full evidence-first Eventbrite importer for collecting review candidates, import previews, and review reports.

== Description ==

Great Imports uses a full-view-first evidence capture model. It accepts a full Eventbrite event detail URL, extracts the numeric Eventbrite event ID, captures raw source evidence into a dedicated evidence record, and only then creates or updates an internal review candidate.

When an Eventbrite private token is configured in WordPress admin, Great Imports captures Eventbrite API evidence and public page evidence. It also captures the public Eventbrite page response and broad HTML-derived evidence such as meta tags, title tags, links, images, canonical URLs, JSON-LD blocks, and script blocks.

The Recent Review Candidates table includes an import preview / dry run. The preview shows what would become public Events Manager event fields, location/address fields, image handling, ticket handling, FAQ dropdowns, internal-only source tracking, and excluded public data. The preview does not save Events Manager events.

The Exploratory Report now includes import-preview sections so the report shows proposed public Events Manager fields, proposed public description, location/address-only handoff, image handling, time/timeslot handling, stage/room handling, internal source tracking, and excluded public/import fields.

Report hygiene redacts secret, cookie, rate-limit, Eventbrite internal header, and structured coordinate fields by field name. Structured latitude/longitude fields are not exported for review/import reporting because Great Imports does not use them.

Great Imports does not geocode, does not transfer latitude/longitude, and does not assign Events Manager location IDs. It prepares address fields only; Events Manager owns saving, updating, mapping, and location ID behavior.

Eventbrite may appear publicly only as the ticket purchase URL. Other Eventbrite source information remains internal Great Imports evidence/source tracking.

Candidate data is a downstream interpretation of evidence, not the evidence source. Relevance decisions, normalization, filtering, mapping, and handoff happen after evidence capture.

Uninstall removes Great Imports-owned data: private token, Great Imports options, `gi_candidate` posts, `gi_evidence` posts, and Great Imports `_gi_` post metadata. It does not delete Events Manager events, Events Manager locations, or media.

This version does not schedule recurring imports, does not directly publish Events Manager events, and does not create Events Manager locations.

== Changelog ==

= 0.2.3 =
* Added import previews to the Exploratory Report.
* Added report summary counts for import previews, excluded public fields, stage/room detection, and timeslot detection.
* Added report hygiene fields for cookies, rate-limit headers, Eventbrite internal headers, and structured coordinate fields.
* Redacts structured latitude/longitude fields in review/import reports because Great Imports does not use them.
* Connects the same preview builder to both the admin screen and the report so they describe the same proposed handoff.

= 0.2.2 =
* Added import preview / dry run for review candidates.
* Added assembled public description preview with overview/details, tickets, organizer, venue, and FAQ dropdowns.
* Added Events Manager location/address preview that excludes latitude, longitude, geocoding, and manual location ID handling.
* Added image handling preview for real event images planned for WordPress Media Library import.
* Added time handling preview for overall event time, set-time evidence, and Events Manager timeslot awareness without automatic conversion.
* Added stage/room duplication rule notes so same-address multiple-stage evidence is not rejected.
* Renamed the one-time action to evidence collection / preview refresh.

= 0.2.1 =
* Fixed uninstall cleanup so Great Imports-owned data is always removed when the plugin is deleted.
* Removed the uninstall cleanup dependency on `great_imports_delete_data_on_uninstall`.
* Deletes Great Imports token/options, candidate records, evidence records, and `_gi_` metadata during uninstall.
* Does not delete Events Manager events, locations, or media.

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
