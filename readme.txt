=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-time Eventbrite URL importer for collecting review candidates.

== Description ==

Great Imports starts with a simple one-time Eventbrite URL import. It accepts a full Eventbrite event detail URL, extracts the numeric Eventbrite event ID, and stores the result as an internal review candidate.

When an Eventbrite private token is configured in WordPress admin, Great Imports tries the Eventbrite API first. It falls back to public Event JSON-LD only when needed. If the source is blocked, unreadable, empty, or missing verified event data, Great Imports records a blocked/unreadable source candidate and fabricates nothing.

This version does not schedule recurring imports, does not directly publish Events Manager events, and does not create Events Manager locations.

== Changelog ==

= 0.1.1 =
* Added masked Eventbrite private token setting.
* Added Eventbrite event ID extraction from full event URLs.
* Added authenticated Eventbrite API fetch path with public JSON-LD fallback.
* Added blocked/unreadable source candidate recording.

= 0.1.0 =
* Initial modular WordPress plugin structure.
* Added one-time Eventbrite URL collection.
* Added internal review candidate storage.
