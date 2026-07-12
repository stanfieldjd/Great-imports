(function () {
    'use strict';

    var config = window.GreatImportsEMLocationTrace || {};
    if (!config.ajaxUrl || !config.nonce || !config.postId) {
        return;
    }

    var startedAt = Date.now();
    var lastState = '';
    var notice;

    function field(id) {
        return document.getElementById(id);
    }

    function coordinatePresent(value) {
        var trimmed = String(value || '').trim();
        if (!trimmed || isNaN(Number(trimmed))) {
            return false;
        }
        return Number(trimmed) !== 0;
    }

    function snapshot(label) {
        var latitude = field('location-latitude');
        var longitude = field('location-longitude');
        var address = field('location-address');
        var town = field('location-town');
        var state = field('location-state');
        var postcode = field('location-postcode');
        var country = field('location-country');
        var latitudePresent = latitude ? coordinatePresent(latitude.value) : false;
        var longitudePresent = longitude ? coordinatePresent(longitude.value) : false;
        var addressPresent = [address, town, state, postcode, country].some(function (item) {
            return item && String(item.value || '').trim() !== '' && String(item.value || '').trim() !== '0';
        });

        return {
            label: label,
            elapsed_ms: Date.now() - startedAt,
            path: window.location.pathname,
            form_present: !!document.getElementById('post'),
            latitude_field_present: !!latitude,
            longitude_field_present: !!longitude,
            latitude_has_value: !!(latitude && String(latitude.value || '').trim() !== ''),
            longitude_has_value: !!(longitude && String(longitude.value || '').trim() !== ''),
            latitude_present: latitudePresent,
            longitude_present: longitudePresent,
            complete: latitudePresent && longitudePresent,
            address_present: addressPresent
        };
    }

    function send(traceEvent, snap, keepalive) {
        var data = new URLSearchParams();
        data.append('action', 'gi_em_location_browser_trace');
        data.append('nonce', config.nonce);
        data.append('post_id', config.postId);
        data.append('trace_event', traceEvent);
        data.append('snapshot', JSON.stringify(snap || snapshot(traceEvent)));

        if (keepalive && navigator.sendBeacon) {
            navigator.sendBeacon(config.ajaxUrl, new Blob([data.toString()], { type: 'application/x-www-form-urlencoded; charset=UTF-8' }));
            return;
        }

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data.toString(),
            keepalive: !!keepalive
        }).catch(function () {});
    }

    function noticeElement() {
        if (notice) {
            return notice;
        }

        notice = document.createElement('div');
        notice.id = 'great-imports-em-location-readiness';
        notice.className = 'notice notice-info inline';
        notice.style.marginTop = '12px';

        var target = document.getElementById('em-location-data') || document.getElementById('location_coordinates');
        if (target && target.parentNode) {
            target.parentNode.insertBefore(notice, target.nextSibling);
        }

        return notice;
    }

    function updateNotice(snap) {
        var element = noticeElement();
        if (!element) {
            return;
        }

        if (!snap.latitude_field_present || !snap.longitude_field_present) {
            element.className = 'notice notice-warning inline';
            element.innerHTML = '<p><strong>Great Imports map trace:</strong> Events Manager coordinate fields were not found on this edit page.</p>';
            return;
        }

        if (snap.complete) {
            element.className = 'notice notice-success inline';
            element.innerHTML = '<p><strong>Great Imports map trace:</strong> Events Manager map coordinates are ready. Click Update to persist the EM-produced coordinates.</p>';
            return;
        }

        if (snap.address_present) {
            element.className = 'notice notice-warning inline';
            element.innerHTML = '<p><strong>Great Imports map trace:</strong> Waiting for Events Manager to populate map coordinates. After clicking OK, wait here until this notice says coordinates are ready, then click Update.</p>';
            return;
        }

        element.className = 'notice notice-info inline';
        element.innerHTML = '<p><strong>Great Imports map trace:</strong> Add a location address so Events Manager can populate map coordinates.</p>';
    }

    function scheduleAfterOkChecks() {
        [250, 1000, 3000, 6000].forEach(function (delay) {
            window.setTimeout(function () {
                var snap = snapshot('after_ok_delay_' + delay + 'ms');
                updateNotice(snap);
                send('after_ok_delay_' + delay + 'ms', snap);
            }, delay);
        });
    }

    function stateKey() {
        var snap = snapshot('coordinate_input_state_key');
        return JSON.stringify({
            form_present: snap.form_present,
            latitude_field_present: snap.latitude_field_present,
            longitude_field_present: snap.longitude_field_present,
            latitude_has_value: snap.latitude_has_value,
            longitude_has_value: snap.longitude_has_value,
            latitude_present: snap.latitude_present,
            longitude_present: snap.longitude_present,
            complete: snap.complete,
            address_present: snap.address_present
        });
    }

    function maybeTraceStateChange() {
        var current = stateKey();
        if (current !== lastState) {
            lastState = current;
            var snap = snapshot('coordinate_input_state_changed');
            updateNotice(snap);
            send('coordinate_input_state_changed', snap);
        }
    }

    function installAlertTrace() {
        var originalAlert = window.alert;
        if ('function' !== typeof originalAlert || originalAlert.__greatImportsWrapped) {
            return;
        }

        window.alert = function (message) {
            var text = String(message || '');
            var expected = window.EM && window.EM.google_maps_resave_location ? String(window.EM.google_maps_resave_location) : '';
            var isMapRefreshAlert = expected ? text === expected : text.indexOf('Location map and coordinates') !== -1;

            if (!isMapRefreshAlert) {
                return originalAlert.apply(window, arguments);
            }

            var before = snapshot('before_ok_alert');
            updateNotice(before);
            send('before_ok_alert', before);
            var result = originalAlert.apply(window, arguments);
            var after = snapshot('after_ok_alert');
            updateNotice(after);
            send('after_ok_alert', after);
            scheduleAfterOkChecks();
            return result;
        };
        window.alert.__greatImportsWrapped = true;
    }

    function installSubmitTrace() {
        var form = document.getElementById('post');
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (event) {
            var snap = snapshot('before_location_form_submit');
            updateNotice(snap);
            send('before_location_form_submit', snap, true);

            if (snap.address_present && snap.latitude_field_present && snap.longitude_field_present && !snap.complete) {
                event.preventDefault();
                event.stopImmediatePropagation();
                send('blocked_location_form_submit_incomplete_coordinates', snap, true);
                window.alert('Events Manager has not populated complete map coordinates yet. Wait until Great Imports says the map coordinates are ready, then click Update.');
            }
        }, true);
    }

    installAlertTrace();

    document.addEventListener('DOMContentLoaded', function () {
        var loaded = snapshot('location_edit_page_loaded');
        updateNotice(loaded);
        send('location_edit_page_loaded', loaded);
        installSubmitTrace();
        maybeTraceStateChange();

        var pollCount = 0;
        var poller = window.setInterval(function () {
            pollCount++;
            maybeTraceStateChange();
            if (pollCount >= 60) {
                window.clearInterval(poller);
            }
        }, 500);
    });
}());
