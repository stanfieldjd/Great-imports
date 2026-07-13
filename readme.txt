=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.60
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full evidence-first Eventbrite importer with candidate review editing, manual data removal, source-page display reports, coverage audits, import previews, and review reports.

== Description ==

Great Imports collects Eventbrite evidence into internal review candidates, then imports reviewed candidates into Events Manager draft events when the Import to Events Manager action is selected.

The main admin screen now puts source search and the Recent Event Candidates list first. Eventbrite settings, report download, and manual data removal are secondary collapsed utility panels.

The Recent Event Candidates list uses a dedicated WP_List_Table-derived class so the candidate rows follow WordPress admin list-table structure instead of custom table markup.

Tickets are read-only source facts. Great Imports does not edit ticket URL, price, currency, or ticket classes. Source/debug details remain behind an Advanced section.

Great Imports prepares reviewed Events Manager event and location fields for storage handoff. A matched Events Manager location can also be selected from the Location area. Explicit source coordinates are carried into the private Events Manager storage handoff when present, and existing coordinate data is preserved unless an explicit replacement decision is recorded.

Manual Data Removal removes only Great Imports-owned data: private token/options, review candidates, evidence records, Great Imports metadata, and Great Imports transients. It does not delete Events Manager events, Events Manager locations, tickets, media, categories, tags, or venue data.

This version does not schedule recurring imports, directly publish Events Manager events, create Events Manager tickets/bookings, or create Media Library attachments. Source images are preserved in the Events Manager description when source evidence provides them.

== Changelog ==

= 0.2.60 =
* Records the last Source Search attempt before and after importer execution so failed or empty searches are visible on the admin screen and in exploratory reports.
* Catches importer exceptions during Source Search and redirects with a recorded error instead of leaving a silent blank outcome.

= 0.2.59 =
* Accepts Eventbrite organizer URLs such as `/o/58111834153` in Search Source.
* Captures organizer-page evidence, extracts Eventbrite event detail links from page HTML/data, and creates review candidates through the existing single-event importer.
* Keeps Search Source candidate-only; organizer collection does not create Events Manager events or locations.

= 0.2.58 =
* Improves automatic Events Manager location matching for combined source address lines.
* Treats contained street-address fragments as same-address evidence for dropdown auto matches.
* Adds ranked Events Manager location fallback matching when narrow database searches miss an obvious venue.

= 0.2.57 =
* Allows automatic Events Manager location matches to appear as selected Matching Location dropdown values.
* Uses the imported Events Manager location ID as a valid automatic dropdown selection when no reviewer override exists.
* Labels automatic dropdown selections as auto matches while still allowing reviewers to save or replace them.

= 0.2.56 =
* Prevents repeat imports from creating duplicate Events Manager events when the candidate link is missing.
* Reuses existing Events Manager events by Great Imports source identity first, then by exact title/date/time/location match.
* Adds the event reuse source to the import trace.

= 0.2.55 =
* Restores primary source images to imported Events Manager descriptions when a source image URL exists and the description does not already contain it.
* Keeps inline description images preserved through candidate storage and Events Manager payload assembly.

= 0.2.54 =
* Removes obsolete imported-content repair wording from the package documentation.
* Keeps the admin utilities limited to Eventbrite API settings, exploratory reports, and Great Imports-owned manual data removal.

= 0.2.53 =
* Removes the automatic Events Manager single-event format mutation added in 0.2.52.
* Keeps `#_OPENSTREETMAP` as trace evidence only so reports identify whether it comes from saved content, Events Manager format settings, or rendered output.

= 0.2.52 =
* Restores sanitized inline event description images instead of stripping all `<img>` tags.
* Repairs Events Manager single-event formats that contain unsupported `#_OPENSTREETMAP` by replacing it with `#_LOCATIONMAP`.

= 0.2.50 =
* Stops generating a public `Good to know` section from derived duration/in-person labels.
* Keeps Events Manager responsible for date/time and location display while retaining source-page Good-to-know evidence in reports.

= 0.2.49 =
* Adds an Events Manager duplicate-location audit to exploratory reports.
* Groups exact and potential active EM duplicate locations by normalized venue name/address, reports hidden postcode/region differences, linked event counts, and coordinate completeness, and suggests a canonical record without merging or deleting anything.

= 0.2.48 =
* Cleans Eventbrite description HTML before it becomes candidate/event body content by removing inline images, source styling, and oversized heading/bold wrappers while preserving text, links, lists, ticket facts, and FAQs.
* Adds a server-side exact Events Manager location reuse check before creating a new location, preferring existing matching locations with coordinates.
* Keeps the `#_OPENSTREETMAP` trace evidence intact; reports identify it as an active Events Manager format placeholder issue, not imported event content.

= 0.2.47 =
* Stops copying Events Manager location state into the separate region field during Great Imports address handoff.
* Prevents `#_LOCATIONFULLLINE` from displaying duplicated state/region values such as `TN` twice in the Events Manager location section.
* Adds `location_region` to location snapshots so reports can show this field before and after import.

= 0.2.46 =
* Stops adding a duplicate venue/address Location section to the Events Manager event description.
* Keeps the canonical address in the Events Manager location payload so the site location display and map continue to be owned by Events Manager.
* Does not change location storage, coordinate handoff, matching, tickets, organizer details, FAQs, or the save workflow.

= 0.2.45 =
* Adds trace-only reporting for unsupported `#_OPENSTREETMAP` placeholders by comparing saved event content, the active Events Manager single-event format, and rendered single-event output.
* Makes the next report able to identify whether the visible placeholder came from stored event content, Events Manager formatting, or render-time filters.
* Does not change import behavior, matching, location storage, or Events Manager save workflow.

= 0.2.44 =
* Completes Eventbrite public-page coordinate handoff from explicit `event:location:latitude` and `event:location:longitude` meta evidence when JSON-LD omits `location.geo`.
* Stores the coordinate pair privately on the candidate with provenance so the existing Events Manager storage handoff can save it server-side.
* Keeps browser autosave, edit-screen OK alerts, geocoding calls, and matching behavior out of this repair.

= 0.2.43 =
* Publishes the matching-location reuse repair as one complete source state.
* No behavior change from 0.2.42.

= 0.2.42 =
* Reuses strong automatic Events Manager location matches during import instead of treating them as display-only suggestions.
* Prefers matching Events Manager locations that already have complete coordinate storage, preserving EM-produced coordinates and avoiding duplicate coordinate-less locations.
* Keeps Great Imports out of browser autosave and the discarded location edit-page map-refresh workflow.

= 0.2.41 =
* Treats zero coordinate placeholders as missing during Events Manager storage handoff decisions.

= 0.2.40 =
* Sends Events Manager event times in database format so imported events can be listed by EM date scopes.
* Sets imported Events Manager events to published EM status.
* Adds event date, time, timezone, and EM status to import traces and exploratory report snapshots.

= 0.2.39 =
* Marks candidates as imported after a successful Events Manager save.
* Shows imported candidates with their Events Manager event ID and changes repeat action wording to Update Events Manager.
* Updates the import success message so it no longer implies the event must be a draft.

= 0.2.38 =
* Captures explicit Eventbrite venue and schema.org GeoCoordinates values as private source evidence.
* Threads complete source coordinate pairs into the Events Manager payload with provenance while keeping raw values redacted from reports.
* Synchronizes Events Manager location post meta and the EM locations table during import, preserving complete existing coordinates and filling missing coordinate surfaces only from explicit source evidence.
* Keeps reviewer-selected existing locations from having their address fields rewritten.

= 0.2.37 =
* Removes the stale map-refresh-required field from Events Manager import traces and exploratory reports.
* Keeps the active workflow focused on Events Manager storage handoff instead of the discarded browser/map-refresh workflow.

= 0.2.36 =
* Removes the Events Manager browser location readiness workflow from Great Imports.
* Removes the location edit-page submit blocker tied to incomplete hidden coordinate fields.
* Updates import preview and reports to describe the Events Manager storage handoff instead of the discarded geocoding/browser workflow.

= 0.2.35 =
* Historical build superseded by 0.2.36 workflow cleanup.

= 0.2.34 =
* Historical build superseded by 0.2.36 workflow cleanup.

= 0.2.33 =
* Historical build superseded by 0.2.36 workflow cleanup.

= 0.2.32 =
* Historical build superseded by 0.2.36 workflow cleanup.

= 0.2.31 =
* Historical coordinate experiment superseded by the 0.2.38 Events Manager storage handoff repair.

= 0.2.30 =
* Historical coordinate experiment superseded by the 0.2.38 Events Manager storage handoff repair.

= 0.2.29 =
* Historical coordinate experiment superseded by the 0.2.38 Events Manager storage handoff repair.

= 0.2.28 =
* Added a visible Import to Events Manager button to every candidate row.
* Added a nonce/capability-protected draft import adapter using EM_Location and EM_Event object save methods.
* Reuses selected locations, creates normalized locations when needed, stores recovered IDs, and updates the same imported event on repeat import.
* Exposed events_manager_payload in exploratory reports.
* Tickets remain description-only; no EM tickets/bookings, automatic publishing, image transfer, or scheduling.

= 0.2.27 =
* Connected the validated Events Manager payload builder to every candidate import preview and exploratory report.
* Supersedes 0.2.26, where the builder method existed but its return value was not attached to the preview array.
* No live Events Manager save, ticket creation, image transfer, scheduling, cleanup, or raw evidence changes.

= 0.2.26 =
* Added a validated Events Manager transfer payload to every candidate import preview and exploratory report.
* Payload includes normalized event dates/times, timezone provenance, assembled description, source identity, and existing/create location strategy.
* Added required-field errors, fallback warnings, and ready_for_save without calling Events Manager save methods.
* Ticket URL and price are explicitly description-only; no Events Manager tickets or bookings are created.
* No live event/location save, image transfer, scheduling, cleanup, or raw evidence changes.

= 0.2.25 =
* Fixed preview date/time formatting so offset-aware source times are not shifted by the WordPress timezone a second time.
* Reused normalized candidate location fields in preview output and the assembled Location description section.
* Added AggregateOffer low/high price fallback so source-backed price and currency appear in ticketing and the assembled description.
* Preserved percent-encoded Eventbrite image and ticket URL components by using URL-specific normalization.
* No Events Manager event, location, ticket, booking, scheduling, cleanup, or raw evidence changes.

= 0.2.24 =
* Reconciled composite fallback addresses with known city, state, ZIP, and country fields for candidate display and editing.
* Extracts a terminal US ZIP only when the pattern is explicit and removes only comma-delimited locality suffixes supported by existing structured evidence.
* Uses the same normalized location fields for the collapsed display, editor inputs, and Events Manager matching.
* Preserves raw source metadata and reviewer overrides and does not fabricate component splits for existing composite addresses.
* No raw evidence, Events Manager records, parser selection, report, cleanup, ticket, or scheduling behavior changes.

= 0.2.23 =
* Recovered the composite JSON-LD location address for display, editing, and Events Manager matching when structured street address data is absent.
* Added structured street, city, state, ZIP, and country fields to newly normalized JSON-LD candidates.
* Preserved reviewer address overrides as the highest-priority values and did not fabricate component splits for existing composite addresses.
* No raw evidence, Events Manager records, parser selection, report, cleanup, ticket, or scheduling behavior changes.

= 0.2.22 =
* Renamed the candidate Venue heading and Venue name editor label to Location and Location name.
* Renamed the Collect Eventbrite URL panel to Source and the Collect evidence button to Search Source.
* Kept the Eventbrite URL placeholder, validator, form action, stored fields, and all importer behavior unchanged.

= 0.2.21 =
* Added independent click-to-open inline editors for candidate Title, Date, and Venue/address fields.
* Added a Matching Location dropdown containing current Events Manager locations and storing only the candidate review location ID.
* Restored a capability-checked, nonce-protected candidate field save handler with strict field-group allowlists.
* Removed the unnecessary outer candidate-table GET form so inline POST forms remain valid and independent.
* Raw source evidence and Events Manager records remain unchanged; edits are stored only as candidate reviewer overrides.

= 0.2.20 =
* Removed the redundant Collect URL page-title shortcut from the rendered admin header.
* Kept the full Collect Eventbrite URL form and its collection action unchanged.
* No candidate, parser, evidence, matching, report, cleanup, ticket, scheduling, or Events Manager behavior changes.

= 0.2.19 =
* Moved the existing Eventbrite API Settings, Exploratory Report, and Manual Data Removal controls above the source search and candidate list.
* Added a read-only Current Version item sourced from the installed Great Imports version constant.
* Arranged the four top utility/status items in a responsive row without duplicating or hiding controls.
* No control actions, parser, evidence capture, candidate storage, matching, report, ticket, scheduling, cleanup, or Events Manager behavior changes.

= 0.2.18 =
* Added the complete stored candidate address beneath the venue name in the candidate list.
* Added a Matching Location column immediately after Venue.
* Displays a reviewer-selected Events Manager location when it is among the current suggestions; otherwise displays only an exact same-name or same-address suggestion.
* Does not present weak partial suggestions as matches and does not create, update, assign, or save Events Manager locations.

= 0.2.17 =
* Removed the fixed admin-screen width cap and right utility sidebar so the candidate list uses the full available WordPress admin content width.
* Moved the existing utility panels below the full-width candidate workflow and arranged them responsively without removing any controls.
* Removed the candidate excerpt width cap so the title column can use the available table width.
* No parser, evidence capture, candidate storage, report generation, ticket handling, scheduling, or Events Manager import behavior changes.

= 0.2.16 =
* Added a dedicated GI_Candidate_List_Table class that extends WordPress' WP_List_Table for candidate rows.
* Replaced the hand-built candidate table output in the admin class with the dedicated list table display path.
* Kept the candidate list free of fake checkboxes, hidden rows, and embedded dry-run/editor markup.
* Reduced candidate-list CSS so WordPress list-table styling carries the table instead of custom report-card styling.
* No parser, evidence capture, ticket handling, report generation, storage, or Events Manager behavior changes.

= 0.2.15 =
* Removed the custom title/description precheck card and returned the screen to one source collection form plus recent candidates.
* Preserved Eventbrite collection, candidate storage, cleanup, report generation, settings, parser, and Events Manager boundaries unchanged.

= 0.2.14 =
* Source display reports now keep the exact user-entered Eventbrite URL for unsupported pages.
* Eventbrite unsupported-page notices now reference the original URL instead of a normalized/stripped variant.
* No parser, candidate, cleanup, Events Manager, ticket, scheduling, or image behavior changes.

= 0.2.13 =
* Fixed source display report availability after unsupported Eventbrite URLs by storing a normalized lookup key.
* Added admin debug comments showing the stored lookup key and report count when a report is not yet rendered.
* No source evidence deletion, parser behavior, candidate storage, cleanup, or Events Manager import behavior changes.

= 0.2.12 =
* Added source-page display reports for Eventbrite URL collection, with a button that downloads a private JSON report.
* Captures rendered text, text links, images, JSON-LD summaries, meta tag names, event-card candidates, and Eventbrite bootstrap summaries for diagnosis.
* Sanitizes secrets, scripts, styles, and likely tracking/session/query identifiers before download.
* Does not change candidate creation, Events Manager import behavior, cleanup, or source evidence retention.

= 0.2.11 =
* Added unsupported-page diagnostics when an Eventbrite URL is reachable but no usable Eventbrite event evidence is found.
* Stores a sanitized display snapshot, text metrics, links, images, JSON-LD summaries, and Eventbrite bootstrap keys for manual review.
* Does not create candidates from unsupported pages and does not change Events Manager, cleanup, scheduling, ticket, or image behavior.

= 0.2.10 =
* Added JSON-LD fallback parsing for Eventbrite pages when embedded API data is absent.
* Source display report downloads are now served from the Great Imports admin page instead of WordPress admin-post.php.
* Report downloads include the collection URL in the filename and generated report payload for verification.

= 0.2.9 =
* Added source-page display report capture for unsupported Eventbrite pages, including rendered text, links, images, JSON-LD, meta tag, Eventbrite bootstrap, and event-card summaries.
* No candidate creation from unsupported pages, no Events Manager writes, no tickets, no scheduling, and no image import.

= 0.2.8 =
* Manual Data Removal now includes Eventbrite API settings and private token state.
* Added a full Great Imports data preview and removal flow that deletes plugin-owned candidates, evidence, metadata, options, transients, and version markers only.
* No Events Manager events, Events Manager locations, tickets, media, posts, pages, categories, tags, or third-party content are removed.

= 0.2.7 =
* Added an admin-only Eventbrite API settings flow with private token storage and a candidate-only Eventbrite API collector.
* API collection uses Eventbrite's official event and venue endpoints, records raw evidence internally, and creates review candidates only.
* Does not import Events Manager events, locations, tickets, categories, images, recurring schedules, or publish content automatically.

= 0.2.6 =
* Hardens candidate collection with a host allow-list for eventbrite.com and www.eventbrite.com.
* Adds candidate metadata fingerprints and source identifiers without changing Events Manager import behavior.

= 0.2.5 =
* Adds the Great Imports admin page with a collect-only Eventbrite URL form and recent candidates table.
* Stores Eventbrite URL evidence as private gi_candidate posts without writing to Events Manager.

= 0.2.4 =
* Scaffold Great Imports plugin with admin shell, versioning, and candidate post type.

= 0.2.3 =
* Initial Great Imports plugin scaffold.
