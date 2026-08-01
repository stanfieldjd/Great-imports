(() => {
    'use strict';

    const submitSourceAction = (button) => {
        const formId = button?.dataset.giSourceForm || '';
        const intent = button?.dataset.giSourceIntent || '';
        const form = formId ? document.getElementById(formId) : null;
        if (!form || !intent) return;
        const intentField = form.querySelector('[data-gi-source-intent-field]');
        if (intentField) intentField.value = intent;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    };

    const positionSourceSettings = (details) => {
        const panel = details?.querySelector('[data-gi-source-settings-panel]');
        const summary = details?.querySelector('summary');
        if (!panel || !summary) return;
        if (window.matchMedia('(max-width: 782px)').matches) {
            panel.classList.remove('is-floating');
            panel.style.removeProperty('left');
            panel.style.removeProperty('top');
            panel.style.removeProperty('width');
            panel.style.removeProperty('max-height');
            return;
        }
        const rect = summary.getBoundingClientRect();
        const adminMenu = document.getElementById('adminmenuwrap')?.getBoundingClientRect();
        const minLeft = Math.max(16, (adminMenu?.right || 0) + 12);
        const availableWidth = Math.max(360, window.innerWidth - minLeft - 20);
        const requestedMax = Number.parseInt(panel.dataset.giPanelMaxWidth || '900', 10);
        const width = Math.min(Number.isFinite(requestedMax) ? requestedMax : 900, availableWidth);
        const left = Math.min(Math.max(rect.left, minLeft), window.innerWidth - width - 16);
        const top = Math.min(rect.bottom + 8, Math.max(16, window.innerHeight - 260));
        panel.classList.add('is-floating');
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
        panel.style.width = `${width}px`;
        panel.style.maxHeight = `${Math.max(220, window.innerHeight - top - 16)}px`;
    };

    document.querySelectorAll('[data-gi-source-settings-dropdown]').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (!details.open) return;
            document.querySelectorAll('[data-gi-source-settings-dropdown][open]').forEach((other) => {
                if (other !== details) other.open = false;
            });
            positionSourceSettings(details);
        });
    });
    window.addEventListener('resize', () => document.querySelectorAll('[data-gi-source-settings-dropdown][open]').forEach(positionSourceSettings));
    window.addEventListener('scroll', () => document.querySelectorAll('[data-gi-source-settings-dropdown][open]').forEach(positionSourceSettings), { passive: true });

    const sourceRadios = [...document.querySelectorAll('[data-gi-input-source]')];
    const urlInput = document.querySelector('[data-gi-url-input]');
    const fileInput = document.querySelector('[data-gi-file-input]');
    const recurringWrap = document.querySelector('[data-gi-recurring-wrap]');

    const syncInputSource = () => {
        const selected = sourceRadios.find((radio) => radio.checked)?.value || 'urls';
        const isFile = selected === 'file';
        if (urlInput) urlInput.hidden = isFile;
        if (fileInput) fileInput.hidden = !isFile;
        if (recurringWrap) recurringWrap.hidden = isFile;
        sourceRadios.forEach((radio) => (radio.closest('.gi-choice-card') || radio.closest('.gi-input-tabs label') || radio.closest('.gi-source-type label'))?.classList.toggle('is-selected', radio.checked));
        if (isFile) {
            const recurring = recurringWrap?.querySelector('input[type="checkbox"]');
            if (recurring) recurring.checked = false;
        }
    };
    sourceRadios.forEach((radio) => radio.addEventListener('change', syncInputSource));
    syncInputSource();

    const syncRecurring = (checkbox) => {
        const form = checkbox.closest('form');
        const fields = form?.querySelector('[data-gi-recurring-fields]');
        if (fields) fields.hidden = !checkbox.checked;
    };
    document.querySelectorAll('[data-gi-recurring]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => syncRecurring(checkbox));
        syncRecurring(checkbox);
    });

    const activeCandidateScope = () => document.querySelector('[data-gi-candidate-search-scope]') || document;
    const candidateCards = (scope = activeCandidateScope()) => [...scope.querySelectorAll('[data-gi-candidate-card]')];
    const candidateCheckboxes = (scope = activeCandidateScope()) => [...scope.querySelectorAll('[data-gi-candidate-checkbox]')];

    const updateSelectedCount = (scope = activeCandidateScope()) => {
        const count = candidateCheckboxes(scope).filter((box) => box.checked).length;
        scope.querySelectorAll('[data-gi-selected-count]').forEach((node) => { node.textContent = String(count); });
        const bar = scope.querySelector('.gi-batch-actionbar');
        if (bar) bar.classList.toggle('has-selection', count > 0);
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-gi-source-location-mode]')) {
            const container = event.target.closest('[data-gi-url-unit]')?.querySelector('[data-gi-single-location]')
                || document.getElementById(event.target.dataset.giLocationForm || '')?.querySelector('[data-gi-single-location]')
                || event.target.closest('form')?.querySelector('[data-gi-single-location]');
            if (container) container.hidden = event.target.value !== 'one_location';
        }
        if (event.target.matches('[data-gi-save-source]')) {
            const form = event.target.closest('form');
            const automaticWrap = form?.querySelector('[data-gi-enable-automatic-wrap]');
            const automatic = form?.querySelector('[data-gi-enable-automatic]');
            if (automaticWrap) automaticWrap.hidden = !event.target.checked;
            if (!event.target.checked && automatic) automatic.checked = false;
        }
        if (event.target.matches('[data-gi-candidate-checkbox]')) {
            updateSelectedCount(event.target.closest('[data-gi-candidate-search-scope]') || activeCandidateScope());
        }
        if (event.target.matches('[data-gi-select-all]')) {
            const scope = event.target.closest('[data-gi-candidate-search-scope]') || activeCandidateScope();
            candidateCheckboxes(scope).forEach((box) => {
                if (box.closest('[data-gi-candidate-card]')?.hidden) return;
                box.checked = event.target.checked;
            });
            updateSelectedCount(scope);
        }
    });

    document.addEventListener('click', (event) => {
        const sourceSubmit = event.target.closest('[data-gi-source-submit]');
        if (sourceSubmit) {
            event.preventDefault();
            submitSourceAction(sourceSubmit);
            return;
        }

        const candidateSubmit = event.target.closest('[data-gi-candidate-submit]');
        if (candidateSubmit) {
            const form = candidateSubmit.closest('form');
            if (!form) return;
            form.querySelectorAll('[data-gi-active-submit]').forEach((button) => {
                button.removeAttribute('data-gi-active-submit');
            });
            candidateSubmit.setAttribute('data-gi-active-submit', '');
            return;
        }

        const selectReady = event.target.closest('[data-gi-select-ready]');
        if (selectReady) {
            const scope = selectReady.closest('[data-gi-candidate-search-scope]') || activeCandidateScope();
            candidateCards(scope).forEach((card) => {
                const box = card.querySelector('[data-gi-candidate-checkbox]');
                if (box) box.checked = card.dataset.giStatus === 'ready';
            });
            const all = scope.querySelector('[data-gi-select-all]');
            if (all) all.checked = false;
            updateSelectedCount(scope);
            return;
        }

        const clearSelection = event.target.closest('[data-gi-clear-selection]');
        if (clearSelection) {
            const scope = clearSelection.closest('[data-gi-candidate-search-scope]') || activeCandidateScope();
            candidateCheckboxes(scope).forEach((box) => { box.checked = false; });
            const all = scope.querySelector('[data-gi-select-all]');
            if (all) all.checked = false;
            updateSelectedCount(scope);
            return;
        }

        const editorTab = event.target.closest('[data-gi-editor-tab]');
        if (editorTab) {
            const form = editorTab.closest('.gi-source-editor-tabs');
            if (!form) return;
            const target = editorTab.dataset.giEditorTab;
            form.querySelectorAll('[data-gi-editor-tab]').forEach((tab) => {
                const active = tab === editorTab;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            form.querySelectorAll('[data-gi-editor-section]').forEach((section) => {
                const active = section.dataset.giEditorSection === target;
                section.hidden = !active;
                section.classList.toggle('is-open', active);
                const content = section.querySelector('.gi-section-content');
                if (content) content.hidden = !active;
            });
            form.querySelector('[data-gi-editor-section]:not([hidden]) input, [data-gi-editor-section]:not([hidden]) select, [data-gi-editor-section]:not([hidden]) textarea')?.focus();
            return;
        }

        const sectionToggle = event.target.closest('[data-gi-section-toggle]');
        if (sectionToggle) {
            const section = sectionToggle.closest('.gi-settings-section');
            const content = section?.querySelector('.gi-section-content');
            if (!section || !content) return;
            const open = content.hidden;
            content.hidden = !open;
            section.classList.toggle('is-open', open);
            sectionToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }

        const addMapping = event.target.closest('[data-gi-add-mapping]');
        if (addMapping) {
            const section = addMapping.closest('.gi-workspace-content, .gi-subsection, form');
            const list = section?.querySelector('[data-gi-mappings], [data-gi-mapping-list]');
            const template = section?.querySelector('template[data-gi-mapping-template]');
            if (list && template) {
                list.querySelector('[data-gi-no-mappings]')?.remove();
                const fragment = template.content.cloneNode(true);
                list.appendChild(fragment);
                list.querySelector('.gi-location-rule-row:last-child input')?.focus();
            }
            return;
        }

        const removeMapping = event.target.closest('[data-gi-remove-mapping]');
        if (removeMapping) {
            const row = removeMapping.closest('.gi-location-rule-row');
            const list = row?.parentElement;
            if (row && list) {
                row.remove();
                if (!list.querySelector('.gi-location-rule-row')) {
                    const empty = document.createElement('div');
                    empty.className = 'gi-no-location-rules';
                    empty.setAttribute('data-gi-no-mappings', '');
                    empty.innerHTML = '<strong>No saved location corrections</strong><p>Add one only when a source spelling or venue label refuses to match.</p>';
                    list.appendChild(empty);
                }
            }
            return;
        }

        const addFestivalSlot = event.target.closest('[data-gi-add-festival-slot]');
        if (addFestivalSlot) {
            const editor = addFestivalSlot.closest('[data-gi-festival-editor]');
            const list = editor?.querySelector('[data-gi-festival-slots]');
            const template = editor?.querySelector('template[data-gi-festival-slot-template]');
            if (list && template) {
                list.appendChild(template.content.cloneNode(true));
                list.querySelector('[data-gi-festival-slot]:last-child input')?.focus();
            }
            return;
        }

        const removeFestivalSlot = event.target.closest('[data-gi-remove-festival-slot]');
        if (removeFestivalSlot) {
            const row = removeFestivalSlot.closest('[data-gi-festival-slot]');
            const list = row?.parentElement;
            row?.remove();
            if (list && !list.querySelector('[data-gi-festival-slot]')) {
                const template = list.closest('[data-gi-festival-editor]')?.querySelector('template[data-gi-festival-slot-template]');
                if (template) list.appendChild(template.content.cloneNode(true));
            }
            return;
        }

        const toggle = event.target.closest('[data-gi-toggle-card]');
        if (toggle) {
            const card = toggle.closest('.gi-candidate');
            const detail = card?.querySelector('[data-gi-card-detail]');
            if (!detail) return;
            document.querySelectorAll('[data-gi-card-detail]:not([hidden])').forEach((openDetail) => {
                if (openDetail === detail) return;
                openDetail.hidden = true;
                openDetail.closest('.gi-candidate')?.querySelector('[data-gi-toggle-card]')?.setAttribute('aria-expanded', 'false');
            });
            detail.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            // The editor expands inside the source page, so the page itself must remain scrollable.
            // A previous modal-body lock made long event forms impossible to reach.
            document.body.classList.remove('gi-review-open');
            window.requestAnimationFrame(() => {
                detail.scrollIntoView({ block: 'start', behavior: 'smooth' });
                detail.querySelector('input,select,textarea,button')?.focus({ preventScroll: true });
            });
            return;
        }

        const close = event.target.closest('[data-gi-close-card]');
        if (close) {
            const detail = close.closest('[data-gi-card-detail]');
            const card = detail?.closest('.gi-candidate');
            if (detail) detail.hidden = true;
            card?.querySelector('[data-gi-toggle-card]')?.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('gi-review-open');
            card?.scrollIntoView({ block: 'nearest' });
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const detail = document.querySelector('[data-gi-card-detail]:not([hidden])');
        if (!detail) return;
        const card = detail.closest('.gi-candidate');
        detail.hidden = true;
        card?.querySelector('[data-gi-toggle-card]')?.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('gi-review-open');
        card?.scrollIntoView({ block: 'nearest' });
    });

    const syncLocationChoice = (form) => {
        const choice = form?.querySelector('[data-gi-location-choice]:checked')?.value || 'detected';
        const existing = form?.querySelector('[data-gi-existing-location]');
        const detected = form?.querySelector('[data-gi-detected-location]');
        if (existing) existing.hidden = choice !== 'existing';
        if (detected) detected.hidden = choice === 'existing';
    };
    document.querySelectorAll('.gi-candidate-form').forEach((form) => {
        form.querySelectorAll('[data-gi-location-choice]').forEach((control) => control.addEventListener('change', () => syncLocationChoice(form)));
        syncLocationChoice(form);
    });


    const syncLocationSelection = (select) => {
        const form = select.closest('form');
        const preview = form?.querySelector('[data-gi-location-preview]');
        const option = select.selectedOptions?.[0];
        const details = form?.querySelector('[data-gi-location-details]');
        const usesExisting = option?.dataset.kind === 'existing';
        if (details) {
            details.classList.toggle('is-disabled', usesExisting);
            details.setAttribute('aria-disabled', usesExisting ? 'true' : 'false');
            if (usesExisting) details.open = false;
            const summary = details.querySelector(':scope > summary');
            if (summary) summary.textContent = usesExisting ? 'Detected venue details (not used)' : 'Venue details found';
            details.querySelectorAll('input,select,textarea').forEach((field) => {
                field.disabled = usesExisting;
            });
        }
        if (preview) {
            const name = option?.dataset.name || 'Location';
            const emName = option?.dataset.emName || name;
            const address = option?.dataset.address || '';
            const note = option?.dataset.note || '';
            const kind = option?.dataset.kind || 'new';
            const nameNode = preview.querySelector('[data-gi-location-preview-name]');
            const emNameNode = preview.querySelector('[data-gi-location-preview-em-name]');
            const addressNode = preview.querySelector('[data-gi-location-preview-address]');
            const noteNode = preview.querySelector('[data-gi-location-preview-note]');
            const stateNode = preview.querySelector('[data-gi-location-preview-state]');
            const icon = preview.querySelector('.dashicons');
            preview.classList.toggle('is-missing', !address);
            preview.classList.toggle('is-existing', kind === 'existing');
            preview.classList.toggle('is-new', kind !== 'existing');
            if (nameNode) nameNode.textContent = name;
            if (emNameNode) {
                emNameNode.textContent = `Saved place: ${emName}`;
                emNameNode.hidden = kind !== 'existing' && emName === name;
            }
            if (addressNode) addressNode.textContent = address || 'Address missing — check the address or choose another place.';
            if (noteNode) noteNode.textContent = note;
            if (stateNode) stateNode.textContent = kind === 'existing' ? 'Already on your website' : 'New place — added with the event';
            if (icon) {
                icon.classList.toggle('dashicons-location-alt', Boolean(address));
                icon.classList.toggle('dashicons-warning', !address);
            }
        }
    };
    document.querySelectorAll('[data-gi-location-selection]').forEach((select) => {
        select.addEventListener('change', () => syncLocationSelection(select));
        syncLocationSelection(select);
    });

    const syncDetectedLocationFields = (form) => {
        const select = form?.querySelector('[data-gi-location-selection]');
        if (!select || select.value !== 'detected') return;
        const option = select.querySelector('option[value="detected"]');
        if (!option) return;
        const value = (name) => form.querySelector(`[name="${name}"]`)?.value?.trim() || '';
        const locationName = value('location_name') || 'Location not resolved';
        const parent = value('parent_location_name');
        const stage = value('stage_name');
        const publicName = parent && stage ? `${stage} at ${parent}` : locationName;
        const emName = parent && stage ? parent : locationName;
        const address = [value('location_address'), value('location_city'), value('location_state'), value('location_postcode')].filter(Boolean).join(', ');
        option.dataset.name = publicName;
        option.dataset.emName = emName;
        option.dataset.address = address;
        option.textContent = parent && stage
            ? `Use detected venue: ${emName} — public display: ${publicName}`
            : `Use ${publicName} (found on the event page)`;
        syncLocationSelection(select);
    };
    document.querySelectorAll('[data-gi-location-details]').forEach((details) => {
        const form = details.closest('form');
        details.querySelectorAll('input,select,textarea').forEach((field) => {
            field.addEventListener('input', () => syncDetectedLocationFields(form));
            field.addEventListener('change', () => syncDetectedLocationFields(form));
        });
    });

    document.querySelectorAll('[data-gi-candidate-search]').forEach((search) => {
        const candidateSearchScope = search.closest('[data-gi-candidate-search-scope]') || activeCandidateScope();
        const visibleCount = candidateSearchScope.querySelector('[data-gi-visible-count]');
        const emptySearch = candidateSearchScope.querySelector('[data-gi-no-search-results]');
        const filterCandidates = () => {
            const query = search.value.trim().toLowerCase();
            let shown = 0;
            candidateSearchScope.querySelectorAll('[data-gi-candidate-card]').forEach((card) => {
                const matches = !query || card.textContent.toLowerCase().includes(query);
                card.hidden = !matches;
                if (matches) shown += 1;
            });
            if (visibleCount) visibleCount.textContent = `${shown} event${shown === 1 ? '' : 's'} shown`;
            if (emptySearch) emptySearch.hidden = shown !== 0;
            updateSelectedCount(candidateSearchScope);
        };
        ['input', 'change', 'search'].forEach((eventName) => search.addEventListener(eventName, filterCandidates));
    });

    const sourceSearch = document.querySelector('[data-gi-source-search]');
    const noSourceResults = document.querySelector('[data-gi-no-source-results]');
    const filterSources = () => {
        if (!sourceSearch) return;
        const query = sourceSearch.value.trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('[data-gi-source-card]').forEach((card) => {
            const matches = !query || card.textContent.toLowerCase().includes(query);
            card.hidden = !matches;
            if (matches) shown += 1;
        });
        if (noSourceResults) noSourceResults.hidden = shown !== 0;
    };
    sourceSearch?.addEventListener('input', filterSources);

    const replaceBrokenEventImage = (image) => {
        const wrap = image.closest('.gi-candidate-thumb');
        if (!wrap || wrap.classList.contains('is-empty')) return;
        wrap.classList.add('is-empty');
        wrap.innerHTML = '<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>';
    };
    document.querySelectorAll('.gi-candidate-thumb img').forEach((image) => {
        image.addEventListener('error', () => replaceBrokenEventImage(image), { once: true });
        if (image.complete && image.naturalWidth === 0) replaceBrokenEventImage(image);
    });

    document.querySelectorAll('[data-gi-source-location-mode]').forEach((select) => {
        const container = select.closest('form')?.querySelector('[data-gi-single-location]');
        if (container) container.hidden = select.value !== 'one_location';
    });
    document.querySelectorAll('[data-gi-save-source]').forEach((checkbox) => {
        const wrap = checkbox.closest('form')?.querySelector('[data-gi-enable-automatic-wrap]');
        if (wrap) wrap.hidden = !checkbox.checked;
    });

    const syncRecurrence = (select) => {
        const form = select.closest('form');
        const fields = form?.querySelector('[data-gi-recurrence-fields]');
        if (fields) fields.hidden = select.value !== 'series';
        const frequency = form?.querySelector('[data-gi-recurrence-frequency]');
        const weekdays = form?.querySelector('[data-gi-recurrence-weekdays]');
        if (weekdays && frequency) weekdays.hidden = frequency.value !== 'weekly';
    };
    document.querySelectorAll('[data-gi-recurrence-mode]').forEach((select) => {
        select.addEventListener('change', () => syncRecurrence(select));
        const frequency = select.closest('form')?.querySelector('[data-gi-recurrence-frequency]');
        frequency?.addEventListener('change', () => syncRecurrence(select));
        syncRecurrence(select);
    });

    const syncEventStructure = (select) => {
        const form = select.closest('form');
        const isFestival = select.value === 'festival';
        const editor = form?.querySelector('[data-gi-festival-editor]');
        const recurrence = form?.querySelector('[data-gi-recurrence-section]');
        if (editor) editor.hidden = !isFestival;
        if (recurrence) {
            recurrence.hidden = isFestival;
            if (isFestival) recurrence.open = false;
        }
        if (isFestival && editor && !editor.querySelector('[data-gi-festival-slot]')) {
            const list = editor.querySelector('[data-gi-festival-slots]');
            const template = editor.querySelector('template[data-gi-festival-slot-template]');
            if (list && template) list.appendChild(template.content.cloneNode(true));
        }
    };
    document.querySelectorAll('[data-gi-event-structure]').forEach((select) => {
        select.addEventListener('change', () => syncEventStructure(select));
        syncEventStructure(select);
    });

    const syncAllDay = (checkbox) => {
        const form = checkbox.closest('form');
        ['start_time', 'end_time'].forEach((name) => {
            const input = form?.elements[name];
            if (!input) return;
            input.disabled = checkbox.checked;
            input.closest('.gi-field')?.classList.toggle('is-disabled', checkbox.checked);
        });
    };
    document.querySelectorAll('[data-gi-all-day]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => syncAllDay(checkbox));
        syncAllDay(checkbox);
    });

    const syncCadence = (select) => {
        const form = select.closest('form');
        const cadence = select.value;
        const hourly = form?.querySelector('[data-gi-hourly-fields]');
        const clock = form?.querySelector('[data-gi-clock-fields]');
        const weekly = form?.querySelector('[data-gi-weekly-fields]');
        const monthly = form?.querySelector('[data-gi-monthly-fields]');
        if (hourly) hourly.hidden = cadence !== 'hourly';
        if (clock) clock.hidden = cadence === 'hourly';
        if (weekly) weekly.hidden = cadence !== 'weekly';
        if (monthly) monthly.hidden = cadence !== 'monthly';
    };
    document.querySelectorAll('[data-gi-cadence]').forEach((select) => {
        select.addEventListener('change', () => syncCadence(select));
        syncCadence(select);
    });

    document.addEventListener('change', (event) => {
        if (!event.target.matches('[data-gi-candidate-location]')) return;
        const select = event.target;
        const option = select.selectedOptions[0];
        const form = select.closest('form');
        if (!form || !option || !select.value) return;
        const existingChoice = form.querySelector('[data-gi-location-choice][value="existing"]');
        if (existingChoice) existingChoice.checked = true;
        syncLocationChoice(form);
        const mapping = {
            location_name: 'name',
            location_address: 'address',
            location_city: 'city',
            location_state: 'state',
            location_postcode: 'postcode',
            location_country: 'country'
        };
        Object.entries(mapping).forEach(([field, dataKey]) => {
            const input = form.elements[field];
            if (input) input.value = option.dataset[dataKey] || '';
        });
    });

    document.querySelectorAll('.gi-source-editor-tabs').forEach((form) => {
        let active = form.querySelector('[data-gi-editor-tab].is-active');
        if (!active) active = form.querySelector('[data-gi-editor-tab]');
        const target = active?.dataset.giEditorTab;
        form.querySelectorAll('[data-gi-editor-section]').forEach((section) => {
            const isActive = section.dataset.giEditorSection === target;
            section.hidden = !isActive;
            const content = section.querySelector('.gi-section-content');
            if (content) content.hidden = !isActive;
        });
    });
    document.querySelectorAll('[data-gi-candidate-search-scope]').forEach((scope) => updateSelectedCount(scope));

    const validateForm = (form) => {
        form.querySelectorAll('.gi-inline-error').forEach((node) => node.remove());
        form.querySelectorAll('.is-invalid').forEach((node) => node.classList.remove('is-invalid'));
        let firstInvalid = null;
        if (form.querySelector('[data-gi-active-submit][data-gi-quick-action]')) return true;
        const addError = (control, message) => {
            const field = control.closest('.gi-field,.gi-big-field') || control.parentElement;
            field?.classList.add('is-invalid');
            const error = document.createElement('div');
            error.className = 'gi-inline-error';
            error.textContent = message;
            field?.appendChild(error);
            if (!firstInvalid) firstInvalid = control;
        };
        if (form.querySelector('input[name="action"][value="gi_create_source"]')) {
            const selected = form.querySelector('[data-gi-input-source]:checked')?.value || 'urls';
            if (selected === 'urls') {
                const input = form.elements.urls;
                const urls = input.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
                if (!urls.length) addError(input, 'Enter at least one URL.');
                else if (urls.some((value) => !/^https?:\/\//i.test(value))) addError(input, 'Every line must begin with http:// or https://.');
            } else {
                const input = form.elements.event_file;
                if (!input.files?.length) addError(input, 'Choose an ICS, CSV, or JSON file.');
            }
        }
        form.querySelectorAll('input[type="number"]').forEach((input) => {
            if (input.value === '') return;
            const value = Number(input.value);
            if (input.min !== '' && value < Number(input.min)) addError(input, `Use a value of ${input.min} or greater.`);
            if (input.max !== '' && value > Number(input.max)) addError(input, `Use a value of ${input.max} or less.`);
        });
        const locationSelection = form.querySelector('[data-gi-location-selection]');
        const locationChoice = locationSelection?.value || form.querySelector('[data-gi-location-choice]:checked')?.value;
        const hasLocationEditor = locationSelection || form.querySelector('[data-gi-location-choice]') || form.elements.location_name || form.elements.location_address;
        if (hasLocationEditor) {
            if (locationChoice?.startsWith('existing:') && !/^existing:\d+$/i.test(locationChoice)) {
                addError(locationSelection || form.querySelector('[data-gi-location-choice]'), 'Choose a place already on your website.');
            }
            if (!locationChoice || locationChoice === 'detected') {
                const locationName = form.elements.location_name?.value.trim() || '';
                const locationAddress = form.elements.location_address?.value.trim() || '';
                if (!locationName && !locationAddress) {
                    addError(form.elements.location_name || locationSelection || form.querySelector('[data-gi-location-choice]'), 'Enter a place name or street address.');
                }
            }
        }
        const recurrenceMode = form.querySelector('[data-gi-recurrence-mode]');
        if (recurrenceMode?.value === 'series') {
            const frequency = form.elements.recurrence_frequency;
            const count = Number(form.elements.recurrence_count?.value || 0);
            const until = form.elements.recurrence_until?.value || '';
            if (!frequency?.value) addError(frequency || recurrenceMode, 'Choose how often the event repeats.');
            if (!count && !until) addError(form.elements.recurrence_count || recurrenceMode, 'Choose an occurrence count or an end date.');
            if (frequency?.value === 'weekly') {
                const days = form.querySelectorAll('input[name="recurrence_weekdays[]"]:checked');
                if (!days.length) addError(frequency, 'Choose at least one weekday.');
            }
        }
        const structure = form.querySelector('[data-gi-event-structure]');
        if (structure?.value === 'festival') {
            const rows = [...form.querySelectorAll('[data-gi-festival-slots] [data-gi-festival-slot]')];
            const filled = rows.filter((row) => row.querySelector('[name="festival_slot_date[]"]')?.value || row.querySelector('[name="festival_slot_title[]"]')?.value);
            if (!filled.length) {
                addError(structure, 'Add at least one festival time slot.');
            }
            filled.forEach((row) => {
                const date = row.querySelector('[name="festival_slot_date[]"]');
                const day = row.querySelector('[name="festival_slot_day[]"]');
                const festivalStart = form.elements.start_date;
                const title = row.querySelector('[name="festival_slot_title[]"]');
                const start = row.querySelector('[name="festival_slot_start_time[]"]');
                if (!date?.value && !(day?.value && festivalStart?.value)) {
                    addError(date || structure, 'Choose the date for this time slot.');
                }
                if (!title?.value.trim()) addError(title || structure, 'Enter the performer or activity.');
                if (!start?.value) addError(start || structure, 'Choose the starting time.');
            });
        }
        const recurring = form.querySelector('[data-gi-recurring]');
        if (recurring?.checked) {
            const cadence = form.elements.cadence?.value || 'daily';
            if (cadence === 'hourly') {
                const minute = form.elements.hourly_minute;
                const value = Number(minute?.value);
                if (!minute || minute.value === '' || !Number.isInteger(value) || value < 0 || value > 59) {
                    addError(minute || recurring, 'Choose a minute from 0 through 59.');
                }
            } else {
                const time = form.elements.run_time;
                if (time && !/^([01]\d|2[0-3]):[0-5]\d$/.test(time.value)) addError(time, 'Choose a valid run time.');
            }
        }
        firstInvalid?.focus();
        return !firstInvalid;
    };

    let dirty = false;
    document.querySelectorAll('.gi-source-editor,.gi-candidate-form').forEach((form) => {
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
    });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });

    const isGreatImportsForm = (form) => {
        const action = form.querySelector('input[name="action"]')?.value || '';
        return action.startsWith('gi_') || Boolean(form.closest('.gi-wrap'));
    };

    const bulkForms = [...document.querySelectorAll('form[id^="gi-bulk-candidates-form-"]')];
    const syncBulkPayload = (bulkForm) => {
        if (!bulkForm) return;
        const scope = bulkForm.closest('[data-gi-candidate-search-scope]') || activeCandidateScope();
        bulkForm.querySelectorAll('[data-gi-bulk-payload]').forEach((input) => input.remove());
        candidateCheckboxes(scope).filter((box) => box.checked).forEach((box) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'candidate_ids[]';
            input.value = box.dataset.giCandidateId || box.value;
            input.setAttribute('data-gi-bulk-payload', '');
            bulkForm.appendChild(input);
        });
    };
    bulkForms.forEach((bulkForm) => bulkForm.addEventListener('submit', () => syncBulkPayload(bulkForm)));

    document.querySelectorAll('form').forEach((form) => {
        if (!isGreatImportsForm(form)) return;
        form.addEventListener('submit', (event) => {
            if (!validateForm(form)) {
                event.preventDefault();
                form.querySelector('[data-gi-active-submit]')?.removeAttribute('data-gi-active-submit');
                return;
            }
            dirty = false;
            const action = form.querySelector('input[name="action"]')?.value || '';
            if (action === 'gi_download_diagnostics' || action === 'gi_download_run') return;
            const button = event.submitter || form.querySelector('[data-gi-active-submit]') || form.querySelector('button[type="submit"],button:not([type])');
            if (button && !button.classList.contains('button-link-delete')) {
                if (button.name) {
                    form.querySelectorAll('[data-gi-submitter-payload]').forEach((input) => input.remove());
                    const submitterPayload = document.createElement('input');
                    submitterPayload.type = 'hidden';
                    submitterPayload.name = button.name;
                    submitterPayload.value = button.value;
                    submitterPayload.setAttribute('data-gi-submitter-payload', '');
                    form.appendChild(submitterPayload);
                }
                button.classList.add('gi-submit-busy');
                button.disabled = true;
                if (action === 'gi_create_source') button.textContent = 'Finding events…';
                else if (action === 'gi_run_source') button.textContent = 'Checking…';
                else if (action === 'gi_update_source' || action === 'gi_save_settings') button.textContent = 'Saving…';
            }
        });
    });


    const syncBulkActionSafety = (scope = activeCandidateScope()) => {
        const selected = candidateCheckboxes(scope).filter((box) => box.checked);
        const hasNonReady = selected.some((box) => box.closest('[data-gi-candidate-card]')?.dataset.giStatus !== 'ready');
        scope.querySelectorAll('.gi-bulk-actions button[name="bulk_action"]').forEach((button) => {
            if (button.value === 'ignore') {
                button.disabled = selected.length === 0;
                return;
            }
            button.disabled = selected.length === 0 || hasNonReady;
            button.title = hasNonReady ? 'Fix the events that need your help before adding them.' : '';
        });
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-gi-candidate-checkbox], [data-gi-select-all]')) {
            syncBulkActionSafety(event.target.closest('[data-gi-candidate-search-scope]') || activeCandidateScope());
        }
    });
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-gi-select-ready], [data-gi-clear-selection]')) {
            const scope = event.target.closest('[data-gi-candidate-search-scope]') || activeCandidateScope();
            window.setTimeout(() => syncBulkActionSafety(scope), 0);
        }
    });
    document.querySelectorAll('[data-gi-candidate-search-scope]').forEach(syncBulkActionSafety);
})();
