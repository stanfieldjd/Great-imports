=== Great Imports ===
Contributors: chattanoogamusicscene
Tags: events, importer, events-manager, ics, csv
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 4.9.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect, review, correct, import, update, and schedule events for Events Manager from URLs, feeds, ICS, CSV, and JSON files.

== Description ==

Great Imports provides one shared pipeline for manual and recurring event imports.

Main workflow:

1. Paste one public event URL or many URLs, or choose an ICS, CSV, or JSON file.
2. Choose what happens after events are found and whether every event should use its detected location or one forced location.
3. Choose Run once, or choose a repeat frequency and Save recurring.
4. Great Imports checks the source immediately. Each submitted URL receives its own event queue on the next page.
5. Review candidates beneath the URL that found them, import ready events in batches, and edit only exceptions.
6. Saved recurring URLs keep their filters, automatic schedule, and manual check control with their own queue.

Included behavior:

* Generic HTML and JSON-LD event collection.
* Event detail-page discovery from listing and calendar pages.
* ICS/iCalendar, CSV, and JSON processing.
* Festival schedules with multiple days, simultaneous stages, and per-slot locations.
* Direct synchronization with Events Manager native time-slot database tables.
* Optional Eventbrite and Ticketmaster detail API enrichment when credentials are configured.
* Deterministic evidence precedence: detail, API, and ICS evidence outrank listing-card evidence.
* Equal-quality conflicts remain visible for review rather than being guessed away.
* Multi-key duplicate matching joins listing, detail-page, iCal, API, and existing Events Manager representations of the same event before review.
* Multi-source enrichment that fills missing fields and retains provenance URLs.
* Administrator candidate corrections preserved as highest-priority evidence.
* Existing-location matching, forced-location rules, and direct Events Manager location table/post-meta synchronization for source-provided coordinates.
* Festival, conference, multi-session, multi-location, stage, room, and parent-venue support.
* Event categories, tags, featured images, ticket URL, price, currency, and organizer content.
* Daily, weekly, and monthly event-series editing, with individually editable Events Manager events for each occurrence.
* ICS RRULE and recurrence fields from supported CSV and JSON sources.
* Per-source run locking to prevent overlapping scheduled and manual writes.
* Context-aware pornography and explicit-nudity filtering with Standard and Strict sensitivity, custom phrases, trusted websites, and manual approval for uncertain events.
* Settings-only import activity and administrator diagnostics, with automatic deletion after 30 days and credentials and coordinate values excluded.
* Uninstall cleanup limited to Great Imports data. Imported Events Manager events, locations, and Media Library files remain intact.

== Administration screens ==

* Import: paste one URL, paste multiple URLs one per line, or choose an event file. Choose Run once or Save recurring on this page.
* Review events: every URL owns one collapsible event queue. Recurring filters and scheduling stay with the saved URL; one-time URLs show no duplicate run/save decision.
* Candidate editor: choose one detected or existing Events Manager location from a single location selector. Correct address or venue structure only when necessary, and optionally save the choice as a reusable source rule.
* Settings → Import activity: review collected, held, imported, updated, blocked, failed, filtered, skipped-existing, and outside-window totals when troubleshooting.
* Settings: set importer defaults, performance safeguards, parser health, automatic removal of completed Run once sources from the active queue, uninstall behavior, and optional platform credentials.

== Acceptance test ==

A release should be considered environment-verified only after this sequence succeeds on the target site:

1. Scan a venue calendar URL in Review mode.
2. Confirm candidates appear with useful hold reasons rather than an empty queue.
3. Correct one candidate if required and import it as draft.
4. Confirm the Events Manager event row and location relationship exist.
5. Re-run the same source and confirm the existing event updates without duplication.
6. Import a short repeating series and confirm each occurrence is independently editable.
7. Save the source as recurring, pause it, and confirm manual Run now still works.
8. Download diagnostics and confirm no credentials or coordinate values are present.

== Changelog ==

= 4.9.9 =
* Added explicit scanner annotations for intentional admin request handling and direct Events Manager database synchronization.
* Replaced the remaining native time-slot table interpolation with prepared identifier placeholders.
* Trimmed the packaged changelog to stay within WordPress.org parser limits.

= 4.9.8 =
* Reworked remaining location-display translation branches so placeholder comments sit directly above each translated placeholder string.

= 4.9.7 =
* Removed remaining scanner-sensitive SQL table interpolation and CSV temporary file writes.
* Repositioned translator comments so placeholder strings are recognized reliably by Plugin Check.

= 4.9.6 =
* Hardened request sanitization, output escaping, dynamic SQL identifiers, temporary files, translations, and WordPress.org package metadata.

Earlier development notes are kept in BUILD-VERIFICATION.txt.
