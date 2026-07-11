=== Great Imports ===
Contributors: greatimports
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.21
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full evidence-first Eventbrite importer with candidate review editing, manual cleanup, source-page display reports, coverage audits, import previews, and review reports.

== Description ==

Great Imports collects Eventbrite evidence into internal review candidates before any Events Manager save. The current workflow is review/dry-run only: it does not create Events Manager events or locations yet.

The main admin screen now puts URL collection and the Recent Event Candidates list first. Eventbrite settings, report download, and manual data removal are secondary collapsed utility panels.

The Recent Event Candidates list uses a dedicated WP_List_Table-derived class so the candidate rows follow WordPress admin list-table structure instead of custom table markup.

Tickets are read-only source facts. Great Imports does not edit ticket URL, price, currency, or ticket classes. Source/debug details remain behind an Advanced section.

Great Imports does not geocode or transfer latitude/longitude. A matched Events Manager location can be selected from the Location area for a later handoff; Events Manager still owns saving, updating, mapping, and location ID behavior.

Manual Data Removal removes only Great Imports-owned data: private token/options, review candidates, evidence records, Great Imports metadata, and Great Imports transients. It does not delete Events Manager events, Events Manager locations, tickets, media, categories, tags, or venue data.

This version does not schedule recurring imports, does not directly publish Events Manager events, and does not create Events Manager locations.

== Changelog ==

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
* Moved the existing Eventbrite API Settings, Exploratory Report, and Manual Data Removal controls above the URL collection and candidate list.
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
* No parser, evidence capture, candidate storage, report generation, ticket handling, scheduling, or Events Manager behavior changes.

= 0.2.16 =
* Added a dedicated GI_Candidate_List_Table class that extends WordPress' WP_List_Table for candidate rows.
* Replaced the hand-built candidate table output in the admin class with the dedicated list table display path.
* Kept the candidate list free of fake checkboxes, hidden rows, and embedded dry-run/editor markup.
* Reduced candidate-list CSS so WordPress list-table styling carries the table instead of custom report-card styling.
* No parser, evidence capture, ticket handling, report generation, storage, or Events Manager import behavior changes.

= 0.2.15 =
* Reworked the main admin page so URL collection and Recent Event Candidates are the primary workflow.
* Moved Eventbrite API settings, Exploratory Report download, and Manual Data Removal into secondary utility panels.
* Kept the candidate table as a list-style screen without embedded dry-run/editor rows.
* Replaced the prior stacked-card layout CSS with admin-screen layout CSS.
* No parser, evidence capture, ticket handling, report generation, storage, or Events Manager import behavior changes.

= 0.2.14 =
* Removed the Open Candidate / Dry Run row and its embedded candidate editor markup from the candidate table output instead of hiding it with CSS.
* Removed the unused admin CSS for the embedded candidate editor/dry-run area.
* Removed the unused admin save hook for candidate review forms from the normal admin page.
* No parser, evidence capture, ticket handling, report generation, storage, or Events Manager import behavior changes.

= 0.2.13 =
* Removed the visible Open Candidate / Dry Run row and the editor/details content rendered inside it from the Recent Event Candidates table.
* Kept the candidate list as a native-style WordPress list-table row with source action only.
* No parser, evidence capture, ticket handling, report generation, storage, or Events Manager import behavior changes.

= 0.2.12 =
* Tightened Recent Event Candidates styling to better match native WordPress list rows.
* Hid the redundant Action column visually and kept actions under the candidate title.
* Restyled the candidate open row so it no longer reads like a large separate report block.
* CSS-only presentation pass; no parser, data model, ticket handling, or import behavior changes.

= 0.2.11 =
* Replaced bulky section-wide candidate editing with slimmer field-level edit rows.
* Removed review status, review decision, address verification, and reviewer notes from the normal candidate UI.
* Moved the Events Manager location match dropdown into the Location area with the address fields.
* Made ticket URL, price, currency, and ticket classes read-only source facts.
* Rendered the description as readable content in the normal UI instead of showing raw HTML.
* Kept source/debug evidence collapsed under Advanced.

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
* Added source page display reports to the Exploratory Report.
* Added source page display reports with visible-text and screenshot-section style breakdowns.
* Added structured comparison sections for captured, missing optional, missing required, excluded, and browser-rendering-gap data.
* Fixed report-download redirects so failed report downloads no longer create unexpected admin-page output.

= 0.2.4 =
* Captures the public Eventbrite event page HTML before API normalization.
* Adds a source-page display report section to the exploratory report.
* Reports visible page text, screenshot-style sections, FAQ items, related-event markers, image evidence, and browser-rendering gaps.

= 0.2.3 =
* Adds review-only import previews for captured candidates.
* Shows proposed Events Manager public fields, date/time handling, location/address fields, ticketing, images, related events, internal tracking, and excluded public data.
* Does not save Events Manager events or locations.

= 0.2.2 =
* Adds exploratory report download for current Great Imports data.
* Reports environment, configured-token status, candidate counts, evidence counts, and tracked metadata.
* Redacts private token values from report output.

= 0.2.1 =
* Fixes admin activation by loading the Eventbrite API normalizer before the importer.

= 0.2.0 =
* Eventbrite one-time evidence collection.
* Private token setting.
* Candidate/evidence custom post types.
* Full-view-first evidence capture.
* Review/dry-run only. No Events Manager save yet.

== Installation ==

Upload the ZIP through WordPress Plugins > Add New > Upload Plugin, then activate Great Imports.

== Uninstall ==

Uninstall removes Great Imports-owned review candidates, evidence records, `_gi_` metadata, plugin options, private token storage, and plugin transients. It does not remove Events Manager data, tickets, media, categories, tags, or venue records.
