=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full evidence-first Eventbrite importer with candidate review editing, manual cleanup, source-page display reports, coverage audits, import previews, and review reports.

== Description ==

Great Imports collects Eventbrite evidence into internal review candidates before any Events Manager save. The current workflow is review/dry-run only: it does not create Events Manager events or locations yet.

The Recent Review Candidates table opens a single Review Candidate / Dry Run screen. Event, location, tickets, description, image, and review decision are edited in separate sections, each with its own save button. Date and time use browser date/time controls instead of raw datetime text fields.

The review screen values are the dry-run values intended for a later Events Manager handoff. Source/debug details remain behind an Advanced section.

Great Imports does not geocode, does not transfer latitude/longitude, and does not assign Events Manager location IDs automatically. It prepares reviewed address fields only; Events Manager owns saving, updating, mapping, and location ID behavior.

Manual Data Removal removes only Great Imports-owned data: private token/options, review candidates, evidence records, Great Imports metadata, and Great Imports transients. It does not delete Events Manager events, Events Manager locations, tickets, media, categories, tags, or venue data.

This version does not schedule recurring imports, does not directly publish Events Manager events, and does not create Events Manager locations.

== Changelog ==

= 0.2.10 =
* Merged candidate review and dry run into one simplified review screen.
* Split event, location, tickets, description, image, and review decision into independently editable sections with their own save buttons.
* Replaced raw datetime text entry with date and time controls.
* Moved source/debug details behind an Advanced section.

= 0.2.9 =
* Reworked candidate review presentation into clearer two-column editable sections.
* Kept event, location/address, ticket/price, location decision, and status/notes fields editable.
* Kept location and address fields prominent in the review editor.
* Kept preserved source values as a separate read-only comparison section.

= 0.2.8 =
* Added a candidate review editor under each review candidate.
* Added editable reviewer override fields for event title, start/end date and time, timezone, location name, address fields, stage/room, ticket URL, price, currency, status, and reviewer notes.
* Added location decision and address verification controls.
* Added read-only Events Manager location suggestions and an existing EM location selector for later import decisions.
* Import previews now prefer reviewer overrides while preserving raw source evidence separately.
* Added reviewer decision details to import previews and reports.
* Moved Manual Data Removal to a collapsed bottom Danger Zone.

= 0.2.7 =
* Rebuilt package with the visible-text helper methods included in `GI_HTML_Evidence_Extractor`.
* Fixes the fatal error from `extract_visible_text_lines()` during evidence collection.
* Keeps the 0.2.6 manual cleanup behavior unchanged.

= 0.2.6 =
* Added Manual Data Removal to the Great Imports admin page.
* Manual cleanup removes only Great Imports-owned candidates, evidence records, `_gi_` metadata, Great Imports options, the Eventbrite private token, and Great Imports transients.
* Manual cleanup does not delete Events Manager events, locations, tickets, media, categories, tags, or venue data.
* Shared the same cleanup path between manual cleanup and uninstall.
* Added confirmation checkbox and REMOVE phrase requirement before cleanup runs.

= 0.2.5 =
* Added source coverage audits to the Exploratory Report.

= 0.2.4 =
* Added source-page display reports to the Exploratory Report for screenshot-style review.

= 0.2.3 =
* Added import-preview sections to the Exploratory Report.

= 0.2.2 =
* Added import preview / dry run for review candidates.

= 0.2.1 =
* Fixed uninstall cleanup so Great Imports-owned data is always removed when the plugin is deleted.

= 0.2.0 =
* Added full-view-first evidence capture foundation.

= 0.1.3 =
* Expanded exploratory API capture beyond selected candidate fields.

= 0.1.2 =
* Added Exploratory Report download.

= 0.1.1 =
* Added masked Eventbrite private token setting.

= 0.1.0 =
* Initial modular WordPress plugin structure.
