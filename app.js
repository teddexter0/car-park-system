'use strict';

const $ = (id) => document.getElementById(id);
const all = (selector) => [...document.querySelectorAll(selector)];
const roles = { student: 'Student', staff: 'Staff', security: 'Security officer', admin: 'Administrator', visitor: 'Visitor' };
const campusZones = [[1, 'A'], [2, 'B'], [3, 'C']];
const zoneCode = (id) => campusZones.find(([seedId]) => seedId === Number(id))?.[1] || `Z${Number(id)}`;
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);
const timestamp = (date) => date ? `${escape(date.slice(0, 16))} EAT` : 'Active';
let csrf = '';
let currentUser = null;
let screen = '';
let gateZones = [];
let adminZones = [];
let activeSessions = [];
let adminSection = 'zones';
let dialogSubmit;
let generation = 0;
let sessionSync;
const sessionEvents = typeof BroadcastChannel === 'function'
    ? new BroadcastChannel(`strathmore-parking:${new URL('.', location.href).pathname}`) : null;
let availabilityRequest;
let dashboardStale = false;
const mobileNavigation = matchMedia('(max-width: 700px)');
let amsRequest = 0;
let sessionsRequest = 0;
let activityRequest = 0;
let assignment = null;
let dashboardZones = [];
let simulatedBay = null;
const bayPages = new Map();
const baysPerPage = 56;
const bayStatuses = { available: 'Vacant', pending: 'Selected', occupied: 'Occupied', booked: 'Booked', unavailable: 'Unavailable' };

function notify(message, error = false, target = 'notice') {
    const element = $(target) || $('notice');
    element.textContent = message;
    if (target !== 'login-error') element.hidden = !message;
    element.classList.toggle('error', error);
}

async function api(action, data, params = {}, signal) {
    const requestGeneration = generation;
    const query = new URLSearchParams({ action, ...params });
    const headers = ['state', 'availability'].includes(action) ? {} : { 'X-CSRF-Token': csrf };
    const options = data === undefined ? { headers } : {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    };
    const url = `api.php?${query}`;
    const response = await fetch(url, { ...options, signal }).catch(() => {
        throw new Error(data === undefined ? 'Could not connect. Refresh and try again.' : 'The server did not confirm the change. Refresh and check the record before retrying.');
    });
    const result = await response.json().catch(() => {
        throw new Error(data === undefined ? 'The server could not load parking data.' : 'The server did not confirm the change. Refresh and check the record before retrying.');
    });
    if (requestGeneration !== generation || signal?.aborted) return null;
    if (!response.ok) {
        const sessionEnded = response.status === 401 && currentUser && action !== 'login';
        const tokenChanged = response.status === 403 && result.error?.startsWith('Your session token');
        if (sessionEnded || tokenChanged) {
            await syncParkingSession();
            return null;
        }
        throw Object.assign(new Error(result.error || 'Request failed.'), { status: response.status });
    }
    if (result.csrf) csrf = result.csrf;
    return result;
}

function syncParkingSession() {
    sessionSync ??= (async () => {
        const previousToken = csrf;
        const state = await api('state');
        if (!state || (state.csrf === previousToken && state.user?.id === currentUser?.id && state.user?.role === currentUser?.role)) return;
        for (const id of ['action-dialog', 'tour-dialog']) {
            if ($(id).open) $(id).close();
        }
        $('login-form').reset();
        updateUser(null);
        notify('');
        if (state.user) {
            updateUser(state.user);
            show('dashboard', true);
            notify(`This tab is now signed in as ${state.user.name}. Review the account before retrying.`);
            void loadDashboard();
        } else {
            show('login', true);
            notify('Your session ended or changed. Sign in again.', true, 'login-error');
        }
    })().finally(() => { sessionSync = null; });
    return sessionSync;
}

function updateUser(user) {
    cancelAvailability();
    generation++;
    dashboardStale = false;
    $('updated').hidden = true;
    $('dashboard-status').textContent = '';
    currentUser = user;
    $('navigation').hidden = !user;
    $('menu-toggle').hidden = !user;
    $('logout').hidden = !user;
    const canUseGate = ['security', 'admin'].includes(user?.role);
    $('student-bay-help').hidden = Boolean(user) && user.role !== 'student';
    $('simulation-scope').hidden = Boolean(user);
    $('admin-bay-help').hidden = user?.role !== 'admin';
    document.querySelector('[data-screen="gate"]').hidden = !canUseGate;
    $('open-gate').hidden = !canUseGate;
    document.querySelector('[data-screen="admin"]').hidden = user?.role !== 'admin';
    $('identity').textContent = user ? `${user.name} · ${roles[user.role]}` : 'Public parking map';
    if (!user) {
        $('checkin-form').reset();
        $('ams-search').value = '';
        $('plate-search').value = '';
        $('active-summary').textContent = '';
        for (const section of ['report', 'audit']) {
            $(`${section}-filters`).reset();
            $(`${section}-status`).textContent = '';
            $(`${section}-results`).hidden = true;
        }
        $('report-window').textContent = '';
        for (const id of ['ams-users', 'checkin-zone', 'dashboard-stats', 'dashboard-zones', 'active-list',
            'zone-list', 'report-period-stats', 'report-stats', 'report-zones', 'history-list', 'audit-list', 'account-list', 'action-fields', 'assignment-map']) {
            $(id).replaceChildren();
        }
        for (const [, code] of campusZones) $(`campus-zone-${code.toLowerCase()}`).replaceChildren();
        gateZones = [];
        adminZones = [];
        activeSessions = [];
        assignment = null;
        dashboardZones = [];
        bayPages.clear();
        dialogSubmit = null;
        toggleVisitorFields();
    }
}

function show(page, moveFocus = false) {
    if (page !== screen) cancelAvailability();
    screen = page;
    $('open-login').hidden = Boolean(currentUser) || page === 'login';
    setMenu(false);
    all('.screen').forEach((section) => { section.hidden = section.id !== `${page}-screen`; });
    all('[data-screen]').forEach((button) => {
        if (button.dataset.screen === page) button.setAttribute('aria-current', 'page');
        else button.removeAttribute('aria-current');
    });
    if (moveFocus) $(`${page}-title`).focus();
}

function setMenu(open) {
    $('menu-toggle').setAttribute('aria-expanded', String(open));
    $('navigation').dataset.open = String(open);
}

function stats(id, values) {
    $(id).innerHTML = values.map(([label, value]) =>
        `<div class="stat"><strong>${escape(value)}</strong><small>${escape(label)}</small></div>`).join('');
}

function zoneCards(id, zones) {
    const container = $(id);
    const paginated = id === 'dashboard-zones';
    const role = currentUser?.role || 'guest';
    const interactive = paginated && ['guest', 'student', 'admin'].includes(role);
    const signature = JSON.stringify([zones, paginated ? [...bayPages] : [], role]);
    if (container.dataset.snapshot === signature && container.childElementCount) return;
    const before = new Map([...container.querySelectorAll('.zone-card')].map((card) =>
        [card.dataset.zoneId, Number(card.dataset.occupied)]));
    const active = document.activeElement;
    const focused = container.contains(active) ? active : null;
    const focusedZone = focused?.closest('[data-zone-id]')?.dataset.zoneId;
    container.dataset.snapshot = signature;
    container.innerHTML = zones.map((zone) => `
        <article class="panel zone-card" ${paginated ? `id="zone-${Number(zone.id)}" tabindex="-1" aria-labelledby="zone-title-${Number(zone.id)}"` : ''} data-zone-id="${Number(zone.id)}" data-occupied="${Number(zone.occupied)}">
            <div class="section-heading"><div class="zone-title"><span class="zone-code">${zoneCode(zone.id)}</span><h3${paginated ? ` id="zone-title-${Number(zone.id)}"` : ''}>${escape(zone.name)}</h3></div>
                <span class="pill ${Number(zone.available) === 0 ? 'warning' : ''}">${zone.available} available</span>
            </div>
            <p class="muted">${escape(zone.description)}</p>
            ${parkingTiles(zone, '', before.get(String(zone.id)), interactive, paginated)}
            <p>${zone.occupied} of ${zone.capacity} occupied</p>
            ${interactive && role !== 'admin' ? `<p id="bay-notice-${Number(zone.id)}" class="notice" role="status" hidden></p>` : ''}
        </article>`).join('') || '<p>No active parking zones.</p>';
    if (focused) {
        const control = $(focused.id);
        const target = (control && !control.disabled && control) || (focusedZone && $(`zone-${focusedZone}`)) || $('dashboard-title');
        target.focus({ preventScroll: true });
    }
}

function renderCampus(zones) {
    for (const [id, code] of campusZones) {
        const area = $(`campus-zone-${code.toLowerCase()}`);
        const zone = zones.find((item) => Number(item.id) === id);
        const name = zone ? escape(zone.name) : 'Zone closed';
        const markup = zone
            ? `<a href="#zone-${id}" class="lot-link"><span class="zone-code">${code}</span><strong>${name}</strong><span class="lot-count ${Number(zone.available) === 0 ? 'lot-full' : ''}">${Number(zone.available)} available</span></a>`
            : `<div class="lot-link lot-unavailable"><span class="zone-code">${code}</span><strong>${name}</strong></div>`;
        if (area.dataset.snapshot === markup && area.childElementCount) continue;
        const link = area.querySelector('a');
        if (link && zone) {
            link.querySelector('strong').textContent = zone.name;
            const count = link.querySelector('.lot-count');
            count.textContent = `${Number(zone.available)} available`;
            count.classList.toggle('lot-full', Number(zone.available) === 0);
        } else {
            const wasFocused = link === document.activeElement;
            area.innerHTML = markup;
            if (wasFocused) $('campus-map-title').focus({ preventScroll: true });
        }
        area.dataset.snapshot = markup;
    }
}

function parkingTiles(zone, phase, previousOccupied, interactive = false, paginated = false) {
    const zoneId = Number(zone.id);
    const capacity = Number(zone.capacity);
    const code = zoneCode(zoneId);
    const explicit = new Map(zone.bays.map((bay) => [bay.number, bay]));
    const pages = Math.ceil(capacity / baysPerPage);
    let page = paginated ? Math.min(bayPages.get(zoneId) || 0, pages - 1) : 0;
    if ((phase === 'pending' && Number(zone.available) > 0) || phase === 'confirmed') {
        let assigned = Number(zone.gate_occupied) + (phase === 'pending' ? 1 : 0);
        for (const number of explicit.keys()) {
            if (number > assigned) break;
            assigned++;
        }
        page = Math.max(0, Math.floor((assigned - 1) / baysPerPage));
    }
    const start = page * baysPerPage + 1;
    const end = Math.min(capacity, start + baysPerPage - 1);
    const tiles = [];
    let gateRank = 0;
    const occupied = Number(zone.occupied);
    const previousGate = previousOccupied === undefined ? Number(zone.gate_occupied)
        : Math.max(0, Number(zone.gate_occupied) + previousOccupied - occupied);
    for (let number = 1; number <= end; number++) {
        const bay = explicit.get(number);
        if (!bay) gateRank++;
        if (number < start) continue;
        const atGate = gateRank <= Number(zone.gate_occupied);
        const pending = phase === 'pending' && gateRank === Number(zone.gate_occupied) + 1;
        const status = bay?.status || (atGate ? 'occupied' : pending ? 'pending' : 'available');
        const changed = !bay && atGate !== (gateRank <= previousGate);
        const label = `${code}${String(number).padStart(2, '0')}`;
        const description = `${label}, ${bay?.mine ? 'occupied by you' : bayStatuses[status]}${bay?.label ? `, ${bay.label}` : ''}`;
        const canClick = interactive && (status === 'available' || (currentUser?.role === 'admin' ? status !== 'occupied' : bay?.mine));
        const car = ['occupied', 'pending'].includes(status) ? '<svg class="icon" aria-hidden="true"><use href="#i-car"/></svg>' : '';
        const statusText = bay?.mine ? 'You' : { booked: 'Booked', unavailable: 'Closed' }[status];
        const content = `${car}<span>${label}</span>${statusText ? `<span class="tile-status">${statusText}</span>` : ''}`;
        const attrs = `class="parking-tile tile-${status}${changed ? ' tile-change' : ''}" title="${escape(description)}"`;
        tiles.push(canClick
            ? `<button type="button" ${attrs} id="bay-${zoneId}-${number}" data-bay="${number}" data-zone-id="${zoneId}" data-status="${status}" aria-label="${escape(description)}">${content}</button>`
            : `<span ${attrs} role="img" aria-label="${escape(description)}"><span class="tile-label" aria-hidden="true">${content}</span></span>`);
    }
    const pager = paginated && pages > 1 ? `<div class="bay-pages">
        <button type="button" id="previous-bays-${zoneId}" aria-describedby="bay-range-${zoneId}" data-bay-page="${page - 1}" data-zone-id="${zoneId}" ${page === 0 ? 'disabled' : ''}>Previous bays</button>
        <small id="bay-range-${zoneId}">Bays ${start}–${end} of ${capacity}</small>
        <button type="button" id="next-bays-${zoneId}" aria-describedby="bay-range-${zoneId}" data-bay-page="${page + 1}" data-zone-id="${zoneId}" ${page === pages - 1 ? 'disabled' : ''}>Next bays</button>
        </div>` : '';
    return `${pager}<div class="parking-tiles">${tiles.join('')}</div>`;
}

function manageBay(zone, number, current) {
    const label = `${zoneCode(zone.id)}${String(number).padStart(2, '0')}`;
    const booking = zone.bays.find((bay) => bay.number === number)?.label || '';
    const options = ['available', 'booked', 'unavailable'].map((status) =>
        `<option value="${status}" ${status === (current === 'available' ? 'booked' : current) ? 'selected' : ''}>${bayStatuses[status]}</option>`);
    openAction(`Manage bay ${label}`, `<label>Status <select name="status">${options.join('')}</select></label>
        <label id="booking-field">Booking for <input name="booking_label" maxlength="100" placeholder="e.g. Directors’ meeting" value="${escape(booking)}"></label>`,
    'Save bay', (form) => api('bay_manage', { zone_id: zone.id, bay_number: number, current, ...Object.fromEntries(new FormData(form)) }));
    const form = $('action-form');
    const showBooking = () => {
        const booked = form.elements.status.value === 'booked';
        $('booking-field').hidden = !booked;
        form.elements.booking_label.required = booked;
    };
    form.elements.status.addEventListener('change', showBooking);
    showBooking();
}

function renderAssignment() {
    const selected = $('checkin-zone').value;
    const zone = gateZones.find((item) => String(item.id) === (assignment?.id || selected));
    const phase = assignment?.phase || (zone ? 'pending' : '');
    const confirmed = phase === 'confirmed';
    const fresh = confirmed && assignment.refreshed && zone;
    const message = confirmed
        ? `Check-in confirmed in ${assignment.zoneName}. Session #${assignment.sessionId}.${fresh ? ` ${zone.available} available.` : ''}`
        : !zone ? 'Choose a zone to preview the assignment.'
            : phase === 'saving' ? `Recording the assignment in ${zone.name}…`
            : `Assigning to ${zone.name}. Confirm with Check in.`;
    if ($('assignment-state').textContent !== message) $('assignment-state').textContent = message;
    const showMap = zone && (!confirmed || fresh);
    $('assignment-map').innerHTML = showMap ? parkingTiles(zone, phase === 'saving' ? 'pending' : phase, assignment?.previous) : '';
    $('assignment-map').dataset.phase = phase;
}

function cancelAvailability() {
    availabilityRequest?.controller.abort();
    availabilityRequest = null;
    $('refresh-dashboard').removeAttribute('aria-disabled');
}

function availabilityStatus(message, error = false) {
    const status = $('dashboard-status');
    status.classList.toggle('error', error);
    if (status.textContent !== message) status.textContent = message;
}

async function loadDashboard({ poll = false, manual = false } = {}) {
    if (poll && (screen !== 'dashboard' || document.hidden || availabilityRequest)) return;
    if (manual && availabilityRequest?.manual) return;
    cancelAvailability();

    const page = screen;
    const controller = new AbortController();
    const request = { controller, manual };
    availabilityRequest = request;
    const timeout = setTimeout(() => controller.abort(), 8000);
    if (manual) {
        $('refresh-dashboard').setAttribute('aria-disabled', 'true');
        availabilityStatus('Refreshing availability…');
    }
    try {
        const result = await api(currentUser ? 'dashboard' : 'availability', undefined, poll ? { poll: '1' } : {}, controller.signal);
        if (availabilityRequest !== request) return;
        if (controller.signal.aborted) throw new Error('Availability request timed out.');
        if (!result) return;
        if (page === 'dashboard') {
            dashboardZones = result.zones;
            renderDashboardMap();
            const time = new Date().toLocaleTimeString([], { timeZone: 'Africa/Nairobi', hour: '2-digit', minute: '2-digit', second: '2-digit' });
            $('updated').textContent = `Updated ${time} EAT`;
            $('updated').hidden = false;
            if (manual || dashboardStale) availabilityStatus('Availability is up to date.');
            dashboardStale = false;
        } else if (page === 'gate') {
            gateZones = result.zones;
            if (assignment?.phase === 'confirmed') assignment.refreshed = true;
            setOptions($('checkin-zone'), availableZones(), 'Select a zone');
            renderAssignment();
        }
    } catch (error) {
        if (availabilityRequest !== request) return;
        if (page !== 'dashboard') throw error;
        const showingPrevious = !$('updated').hidden;
        const message = showingPrevious ? 'Could not refresh availability. Showing the last available data.'
            : 'Could not load availability. Select Refresh availability to try again.';
        availabilityStatus(message, true);
        dashboardStale = true;
    } finally {
        clearTimeout(timeout);
        if (availabilityRequest === request) {
            availabilityRequest = null;
            $('refresh-dashboard').removeAttribute('aria-disabled');
        }
    }
}

function renderDashboardMap() {
    const selected = !currentUser && simulatedBay;
    const zones = dashboardZones.map((zone) => {
        if (!selected || Number(zone.id) !== selected.zoneId) return zone;
        const before = zone.bays.filter((bay) => bay.number < selected.number).length;
        if (selected.number > Number(zone.capacity) || selected.number - before <= Number(zone.gate_occupied)
            || zone.bays.some((bay) => bay.number === selected.number)) {
            simulatedBay = null;
            return zone;
        }
        const bays = [...zone.bays, { number: selected.number, status: 'occupied', mine: true }];
        return { ...zone, bays, occupied: Number(zone.occupied) + 1, available: Number(zone.available) - 1 };
    });
    if (selected && !zones.some((zone) => Number(zone.id) === selected.zoneId)) simulatedBay = null;
    const capacity = zones.reduce((sum, zone) => sum + Number(zone.capacity), 0);
    const occupied = zones.reduce((sum, zone) => sum + Number(zone.occupied), 0);
    const available = zones.reduce((sum, zone) => sum + Number(zone.available), 0);
    stats('dashboard-stats', [['Total capacity', capacity], ['Occupied spaces', occupied], ['Available spaces', available],
        ['Occupancy', `${capacity ? Math.round(occupied * 100 / capacity) : 0}%`]]);
    zoneCards('dashboard-zones', zones);
    renderCampus(zones);
}

function availableZones(exclude = 0) {
    return gateZones.filter((zone) => Number(zone.id) !== exclude).map((zone) => ({
        id: zone.id,
        label: `${zoneCode(zone.id)} · ${zone.name} (${zone.available} available)`,
        disabled: Number(zone.available) === 0,
    }));
}

function optionMarkup(items, placeholder) {
    return `<option value="">${escape(placeholder)}</option>` + items.map(({ id, label, disabled }) =>
        `<option value="${escape(id)}" ${disabled ? 'disabled' : ''}>${escape(label)}</option>`).join('');
}

function setOptions(select, items, placeholder) {
    const previous = select.value;
    select.innerHTML = optionMarkup(items, placeholder);
    if (items.some(({ id, disabled }) => String(id) === previous && !disabled)) select.value = previous;
}

async function loadAms() {
    const request = ++amsRequest;
    const result = await api('ams', undefined, { q: $('ams-search').value });
    if (!result || request !== amsRequest) return;
    const users = result.users.map((user) => ({ id: user.id, label: `${user.name} · ${user.institutional_id}` }));
    setOptions($('ams-users'), users, users.length ? 'Select an account' : 'No matches');
}

function record(title, detail, actions = '', className = '') {
    return `<article class="record ${className}"><div><strong>${title}</strong>${detail}</div>${actions ? `<div class="record-actions">${actions}</div>` : ''}</article>`;
}

async function loadActive() {
    const request = ++sessionsRequest;
    const result = await api('active', undefined, { q: $('plate-search').value });
    if (!result || request !== sessionsRequest) return;
    activeSessions = result.sessions;
    const count = activeSessions.length;
    $('active-summary').textContent = count === 100 ? 'Showing up to 100 active sessions. Search to narrow the results.'
        : `${count} active ${count === 1 ? 'session' : 'sessions'} found.`;
    $('active-list').innerHTML = activeSessions.map((session) => record(
        `${escape(session.vehicle_plate)} <small>Session #${session.id}</small>`,
        `<small>${escape(session.driver)} · ${roles[session.driver_type]} · ${escape(session.zone)}</small>
         <small>Since ${timestamp(session.checkin_at)}</small>
         ${session.destination ? `<small>${escape(session.purpose)} · ${escape(session.destination)}</small>` : ''}
         ${Number(session.needs_review) ? '<span class="pill warning">Review: parked over 12 hours</span>' : ''}
         ${Number(session.id_retained) ? '<span class="pill warning">Physical ID held</span>' : ''}`,
        `<button data-operation="checkout" data-id="${session.id}">Check out</button>
         <button data-operation="transfer" data-id="${session.id}">Transfer</button>
         <button data-operation="correct_checkout" data-id="${session.id}">Correct missed exit</button>`,
    )).join('') || '<p>No matching active sessions.</p>';
}

async function loadAdmin(section = adminSection) {
    adminSection = section;
    all('[data-admin]').forEach((button) => {
        const selected = button.dataset.admin === section;
        button.setAttribute('aria-pressed', selected);
        $(`admin-${button.dataset.admin}`).hidden = !selected;
    });
    if (section === 'report' || section === 'audit') return loadAdminActivity(section);
    const result = await api(section);
    if (!result) return;
    if (section === 'zones') {
        adminZones = result.zones;
        $('zone-list').innerHTML = adminZones.map((zone) => record(escape(zone.name),
            `<small>${escape(zone.description)}</small><small>${zone.occupied} / ${zone.capacity} occupied · ${Number(zone.active) ? 'Active' : 'Inactive'}</small>`,
            `<button data-zone="edit" data-id="${zone.id}">Edit</button><button data-zone="delete" data-id="${zone.id}">Delete</button>`,
        )).join('') || '<p>No zones. Add one to get started.</p>';
    } else {
        $('account-list').innerHTML = result.accounts.map((account) => record(escape(account.name),
            `<small>${escape(account.email)} · ${roles[account.role]} · ${Number(account.active) ? 'Active' : 'Inactive'}</small>
             <small>${escape(account.institutional_id || '')}</small>`,
            account.id == currentUser.id ? '' :
                `<button data-account="${account.id}" data-active="${Number(account.active) ? 0 : 1}">${Number(account.active) ? 'Deactivate' : 'Reactivate'}</button>`,
        )).join('');
    }
}

const activityLabel = (value) => value.replaceAll('_', ' ').replace(/^./, (first) => first.toUpperCase());
const activityRange = (window) => window.from === window.through ? `${window.from} EAT`
    : `${window.from} through ${window.through} EAT`;

async function loadAdminActivity(section) {
    const request = ++activityRequest;
    const isCurrent = () => request === activityRequest && screen === 'admin' && adminSection === section;
    const status = $(`${section}-status`);
    const results = $(`${section}-results`);
    status.textContent = 'Loading results…';
    status.classList.remove('error');
    results.hidden = true;
    try {
        const result = await api(section, undefined, Object.fromEntries(new FormData($(`${section}-filters`))));
        if (!result || !isCurrent()) return;
        if (section === 'report') renderReport(result);
        else renderAudit(result);
        const count = section === 'report' ? result.sessions.length : result.logs.length;
        const noun = section === 'report' ? 'session' : 'event';
        const summary = result.more ? `Showing the first 100 ${noun}s. Narrow the filters for more specific results.`
            : `${count} matching ${noun}${count === 1 ? '' : 's'}.`;
        status.textContent = section === 'audit' && result.window ? `${activityRange(result.window)}. ${summary}` : summary;
        results.hidden = false;
    } catch (error) {
        if (!isCurrent()) return;
        status.classList.add('error');
        status.textContent = error.message;
    }
}

function renderAudit(result) {
    setOptions($('audit-filters').elements.event, result.events.map((action) => ({
        id: action, label: activityLabel(action),
    })), 'All events');

    $('audit-list').innerHTML = result.logs.length
    ? result.logs.map((log) => {
        const details = Object.entries(JSON.parse(log.metadata)).map(([key, value]) => {
            if (key === 'reason_recorded') return 'Reason recorded';
            const label = activityLabel(key);
            if (typeof value === 'boolean') return `${label}: ${value ? 'Yes' : 'No'}`;
            return `${label}: ${value}`;
        }).join(' · ');
        return record(escape(activityLabel(log.action)),
            `<small>${escape(log.actor || 'Unauthenticated')} · ${timestamp(log.created_at)} · ${escape(activityLabel(log.entity_type))} ${escape(log.entity_id)}</small>
             ${details ? `<small>${escape(details)}</small>` : ''}`);
    }).join('')
    : '<div class="empty-state">No audit events found. Try changing your filters.</div>';

}

function renderReport(result) {
    setOptions($('report-filters').elements.zone_id, result.history_zones.map((zone) => ({
        id: zone.id, label: Number(zone.active) ? zone.name : `${zone.name} (inactive)`,
    })), 'All zones');
    $('report-window').textContent = activityRange(result.window);
    stats('report-period-stats', [['Gate entries', result.entries], ['Recorded exits', result.exits]]);
    stats('report-stats', [['Occupied now', result.occupied], ['Available now', result.available], ['Active visitor sessions', result.active_visitors]]);
    zoneCards('report-zones', result.zones);

    $('history-list').innerHTML = result.sessions.length
    ? result.sessions.map((session) => record(
        session.driver_type === 'visitor' ? `Visitor session #${Number(session.id)}` : `${escape(session.vehicle_plate)} · #${Number(session.id)}`,
        `<small>${escape(session.driver || 'Visitor')} · ${escape(session.zone)}</small>
         <small>Entry ${timestamp(session.checkin_at)} · ${session.checkout_at ? `Exit ${timestamp(session.checkout_at)}` : 'Active now'}</small>`,
    )).join('')
    : '<div class="empty-state">No parking sessions found for this period. Try changing your filters.</div>';

}

function toggleVisitorFields() {
    const visitor = $('checkin-form').elements.kind.value === 'visitor';
    $('visitor-fields').hidden = $('visitor-fields').disabled = !visitor;
    $('institution-fields').hidden = $('institution-fields').disabled = visitor;
}

function openAction(title, fields, label, submit) {
    $('action-title').textContent = title;
    $('action-fields').innerHTML = fields;
    $('action-submit').textContent = label;
    notify('', false, 'action-error');
    dialogSubmit = submit;
    $('action-dialog').showModal();
}

function zoneDialog(operation, zone) {
    if (operation === 'delete') {
        openAction('Delete parking zone', `<p>Delete ${escape(zone.name)}? Only zones with no session history can be deleted.</p>`, 'Delete zone',
            () => api('zone_delete', { id: zone.id }));
        return;
    }
    openAction(zone ? 'Edit parking zone' : 'Add parking zone', `
        <label>Zone name <input required name="name" maxlength="80" value="${escape(zone?.name)}"></label>
        <label>Description <input name="description" maxlength="250" value="${escape(zone?.description)}"></label>
        <label>Capacity <input required type="number" name="capacity" min="1" max="9999" value="${escape(zone?.capacity)}"></label>
        <label class="check"><input type="checkbox" name="active" ${!zone || Number(zone.active) ? 'checked' : ''}> Active zone</label>`,
    'Save zone', (form) => api(zone ? 'zone_update' : 'zone_create', {
        ...Object.fromEntries(new FormData(form)), id: zone?.id, active: form.elements.active.checked,
    }));
}

function gateDialog(operation, session) {
    const plate = escape(session.vehicle_plate);
    const heldId = Number(session.id_retained) ? '<p><strong>Return the physically retained ID.</strong></p>' : '';
    const transfer = operation === 'transfer';
    const checkout = operation === 'checkout';
    const reason = `<label>${transfer ? 'Transfer reason' : 'Correction reason'} <input name="reason" required maxlength="250"></label>`;
    const destinations = availableZones(Number(session.zone_id));
    if (transfer && !destinations.some((zone) => !zone.disabled)) {
        notify('No other zone has space. Refresh availability before transferring.', true);
        return;
    }
    const fields = transfer
        ? `<p>Current zone: ${escape(session.zone)}</p><label>New zone <select name="zone_id" required>${optionMarkup(destinations, 'Select a zone')}</select></label>${reason}`
        : `<p>Confirm that ${plate} has departed.</p>${heldId}${checkout ? '' : reason}`;
    openAction(transfer ? `Transfer ${session.vehicle_plate}` : checkout ? 'Check out vehicle' : 'Correct missed checkout', fields,
        transfer ? 'Record transfer' : 'Record checkout',
        async (form) => {
            try {
                const result = await api(operation, {
                    ...Object.fromEntries(new FormData(form)), id: session.id, expected_zone_id: session.zone_id,
                });
                if (!result) return null;
                const message = transfer ? `${session.vehicle_plate} moved from ${result.from_zone} to ${result.to_zone}.`
                    : `${checkout ? 'Checkout recorded' : 'Missed exit corrected'} for ${session.vehicle_plate}.`;
                return { ...result, message };
            } catch (error) {
                if ([404, 409].includes(error.status)) {
                    await Promise.allSettled([loadDashboard(), loadActive()]);
                }
                throw error;
            }
        });
}

function run(event, work, errorTarget = 'notice') {
    const form = event.type === 'submit' ? event.currentTarget : null;
    const button = event.submitter || form?.querySelector('[type="submit"]') || event.currentTarget;
    const lock = button instanceof HTMLButtonElement && (form || button.matches('[data-bay], #logout, #refresh-active'));
    if (form?.dataset.saving === 'true' || (lock && button.getAttribute('aria-disabled') === 'true')) return;
    if (form) form.dataset.saving = 'true';
    if (lock) button.setAttribute('aria-disabled', 'true');
    void Promise.resolve().then(work).catch((error) => {
        if ($(errorTarget)?.closest('[hidden]:not(.notice)')) return;
        notify(error.message, true, errorTarget);
    }).finally(() => {
        if (lock) button.removeAttribute('aria-disabled');
        if (form) delete form.dataset.saving;
    });
}

function onSearch(id, work) {
    let timer;
    $(id).addEventListener('input', (event) => {
        clearTimeout(timer);
        timer = setTimeout(() => { if (currentUser) run(event, work); }, 220);
    });
}

const tour = [
    ['Try the sensor demo', 'Click a green bay on the public map to simulate arrival. Click the red bay again to simulate leaving.'],
    ['Check available spaces', 'Find Visitor Parking. Blue bays are booked and grey bays are unavailable.'],
    ['Manage a parking zone', 'Sign in as admin@parking.test with the demo password ParkingDemo26!. Add a temporary zone, edit its capacity, then delete it while it has no history.'],
    ['Switch to the security desk', 'Sign out and sign in as security@parking.test with the same password. Open Gate operations.'],
    ['Enter a fictional visitor', 'Choose Visitor. Use Quinn Demo, Interview, Careers Office and vehicle DEMO701. Select Visitor Parking. Mark the physical ID inspected and retained for the demo.'],
    ['Record the vehicle entry', 'Click Check in. The vehicle should appear in Active sessions with its assigned zone.'],
    ['Verify occupancy increased', 'Open Dashboard. Visitor Parking should have one fewer available space.'],
    ['Record the departure', 'Return to Gate operations. Find DEMO701 and check it out. The system reminds the officer to return the physical ID.'],
    ['Verify a space reopened', 'Open Dashboard. Visitor Parking should be back at its starting availability.'],
    ['Review the audit trail', 'Switch back to the administrator account and open Administration, then Audit log. Find vehicle entry, vehicle exit and your zone changes.'],
];
let step = 0;
function renderTour() {
    $('tour-step').textContent = `Step ${step + 1} of ${tour.length}`;
    $('tour-progress').value = step + 1;
    $('tour-heading').textContent = tour[step][0];
    $('tour-copy').textContent = tour[step][1];
    $('tour-prev').disabled = step === 0;
    $('tour-next').textContent = step === tour.length - 1 ? 'Finish' : 'Next';
}

$('login-form').addEventListener('submit', (event) => {
    event.preventDefault();
    run(event, async () => {
        notify('', false, 'login-error');
        const result = await api('login', Object.fromEntries(new FormData(event.target)));
        if (!result) return;
        event.target.reset();
        updateUser(result.user);
        sessionEvents?.postMessage('changed');
        show('dashboard', true);
        await loadDashboard();
    }, 'login-error');
});
$('logout').addEventListener('click', (event) => run(event, async () => {
    if (!await api('logout', {})) return;
    updateUser(null);
    sessionEvents?.postMessage('changed');
    show('dashboard', true);
    notify('Signed out.');
    await loadDashboard();
}));
$('menu-toggle').addEventListener('click', () => setMenu($('navigation').dataset.open !== 'true'));
mobileNavigation.addEventListener('change', ({ matches }) => {
    if (matches && $('navigation').contains(document.activeElement)) $('menu-toggle').focus();
    setMenu(false);
});
document.addEventListener('keydown', (event) => {
    const inMenu = $('navigation').contains(event.target) || event.target === $('menu-toggle');
    if (event.key === 'Escape' && mobileNavigation.matches && inMenu && $('navigation').dataset.open === 'true') {
        setMenu(false);
        $('menu-toggle').focus();
    }
});
all('[data-screen], [data-open-screen]').forEach((button) => button.addEventListener('click', (event) => {
    const page = button.dataset.screen || button.dataset.openScreen;
    notify('');
    notify('', false, 'login-error');
    show(page, true);
    run(event, async () => {
        try {
            if (page === 'dashboard') await loadDashboard();
            if (page === 'gate') await Promise.all([loadDashboard(), loadAms(), loadActive()]);
            if (page === 'admin') await loadAdmin('zones');
        } catch (error) {
            if (screen === page) throw error;
        }
    });
}));
$('refresh-dashboard').addEventListener('click', () => { void loadDashboard({ manual: true }); });
all('[data-admin]').forEach((button) => button.addEventListener('click', (event) => run(event, () => loadAdmin(button.dataset.admin))));
all('[data-demo]').forEach((button) => button.addEventListener('click', () => {
    const form = $('login-form');
    form.elements.email.value = `${button.dataset.demo}@parking.test`;
    form.elements.password.value = 'ParkingDemo26!';
    form.querySelector('[type="submit"]').focus();
}));
$('checkin-form').elements.kind.addEventListener('change', toggleVisitorFields);
$('checkin-zone').addEventListener('change', () => {
    if (assignment?.phase === 'saving') return;
    assignment = null;
    renderAssignment();
});
$('checkin-form').addEventListener('submit', (event) => {
    event.preventDefault();
    run(event, async () => {
        const data = Object.fromEntries(new FormData(event.target));
        if (data.kind === 'visitor') {
            data.id_inspected = event.target.elements.id_inspected.checked;
            data.id_retained = event.target.elements.id_retained.checked;
        }
        const previous = Number(gateZones.find((zone) => String(zone.id) === data.zone_id)?.occupied);
        assignment = { id: data.zone_id, phase: 'saving', previous };
        renderAssignment();
        let result;
        try {
            result = await api('checkin', data);
        } catch (error) {
            assignment = null;
            renderAssignment();
            if ([404, 409].includes(error.status)) {
                $('plate-search').value = data.vehicle_plate;
                await Promise.allSettled([loadDashboard(), loadActive()]);
            }
            throw error;
        }
        if (!result) return;
        assignment = { id: data.zone_id, phase: 'confirmed', previous, zoneName: result.zone, sessionId: result.id, refreshed: false };
        event.target.reset();
        toggleVisitorFields();
        renderAssignment();
        $('ams-search').value = '';
        $('plate-search').value = '';
        notify(`Vehicle checked in to ${result.zone}. Session #${result.id}.`);
        try {
            await Promise.all([loadDashboard(), loadAms(), loadActive()]);
        } catch (error) {
            notify(`Check-in was saved. Refresh failed: ${error.message}`, true);
        }
    });
});
$('refresh-active').addEventListener('click', (event) => run(event, () => Promise.all([loadActive(), loadDashboard()])));
onSearch('ams-search', loadAms);
onSearch('plate-search', loadActive);
for (const section of ['report', 'audit']) {
    const form = $(`${section}-filters`);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void loadAdminActivity(section);
    });
    form.querySelector('[data-reset-filters]').addEventListener('click', () => {
        form.reset();
        void loadAdminActivity(section);
    });
}
$('add-zone').addEventListener('click', () => zoneDialog('add'));
$('zone-list').addEventListener('click', (event) => {
    const button = event.target.closest('[data-zone]');
    if (button) zoneDialog(button.dataset.zone, adminZones.find((zone) => zone.id == button.dataset.id));
});
$('account-list').addEventListener('click', (event) => {
    const button = event.target.closest('[data-account]');
    if (!button) return;
    const active = button.dataset.active === '1';
    openAction(active ? 'Reactivate account' : 'Deactivate account',
        active ? '<p>Restore sign-in access for this demo account?</p>' : '<p>Disable sign-in access for this demo account?</p>',
        'Confirm', () => api('account_status', { id: button.dataset.account, active }));
});
$('active-list').addEventListener('click', (event) => {
    const button = event.target.closest('[data-operation]');
    if (button) gateDialog(button.dataset.operation, activeSessions.find((session) => session.id == button.dataset.id));
});
$('dashboard-zones').addEventListener('click', (event) => {
    const control = event.target.closest('[data-bay-page]');
    if (control) {
        const zoneId = Number(control.dataset.zoneId);
        bayPages.set(zoneId, Number(control.dataset.bayPage));
        renderDashboardMap();
        const sameControl = $(control.id);
        const target = sameControl && !sameControl.disabled ? sameControl
            : $(`zone-${zoneId}`).querySelector('[data-bay-page]:enabled');
        target?.focus({ preventScroll: true });
        return;
    }
    const button = event.target.closest('[data-bay]');
    if (!button) return;
    const zone = dashboardZones.find((item) => Number(item.id) === Number(button.dataset.zoneId));
    const number = Number(button.dataset.bay);
    const current = button.dataset.status;
    if (!currentUser) {
        const target = `bay-notice-${Number(zone.id)}`;
        if (simulatedBay && current !== 'occupied') {
            notify('Select your occupied bay again to leave before choosing another.', true, target);
            return;
        }
        const leaving = current === 'occupied';
        simulatedBay = leaving ? null : { zoneId: Number(zone.id), number };
        renderDashboardMap();
        $(`bay-${Number(zone.id)}-${number}`)?.focus({ preventScroll: true });
        notify(leaving ? 'Departure simulated. The bay is vacant.' : 'Arrival simulated. Select your occupied bay again to leave.', false, target);
    } else if (currentUser.role === 'admin') {
        manageBay(zone, number, current);
    } else if (currentUser.role === 'student') {
        const target = `bay-notice-${Number(zone.id)}`;
        run({ currentTarget: button }, async () => {
            notify('', false, target);
            const releasing = current === 'occupied';
            const result = await api(releasing ? 'bay_release' : 'bay_occupy', { zone_id: zone.id, bay_number: number, current });
            if (!result) return;
            await loadDashboard();
            if ($(target)) {
                notify(releasing ? 'Departure simulated. The bay is vacant.' : 'Arrival simulated. Click your bay again to leave.', false, target);
            }
        }, target);
    }
});
$('action-form').addEventListener('submit', (event) => {
    event.preventDefault();
    run(event, async () => {
        notify('', false, 'action-error');
        const result = await dialogSubmit(event.target);
        if (!result) return;
        $('action-dialog').close();
        const message = result.message || 'Parking record updated.';
        notify(result.return_id ? `${message} Return the visitor’s physically retained ID.` : message);
        try {
            if (screen === 'gate') await Promise.all([loadDashboard(), loadActive()]);
            if (screen === 'admin') await loadAdmin();
            if (screen === 'dashboard') await loadDashboard();
        } catch (error) {
            notify(`The change was saved. Refresh failed: ${error.message}`, true);
        }
    }, 'action-error');
});
$('action-dialog').addEventListener('cancel', (event) => {
    if ($('action-form').dataset.saving === 'true') event.preventDefault();
});
all('[data-close]').forEach((button) => button.addEventListener('click', () => {
    if (button.dataset.close === 'action-dialog' && $('action-form').dataset.saving === 'true') return;
    $(button.dataset.close).close();
}));
$('tour-button').addEventListener('click', () => {
    renderTour();
    $('tour-dialog').showModal();
});
$('tour-prev').addEventListener('click', () => { step--; renderTour(); });
$('tour-next').addEventListener('click', () => {
    if (step === tour.length - 1) {
        $('tour-dialog').close();
        step = 0;
    } else {
        step++;
        renderTour();
    }
});

sessionEvents?.addEventListener('message', () => {
    if (!currentUser && screen !== 'login') return;
    void syncParkingSession().catch(() => {});
});

const pollDashboard = () => { void loadDashboard({ poll: true }); };
setInterval(pollDashboard, 10000);
document.addEventListener('visibilitychange', pollDashboard);

async function initialize() {
    const state = await api('state');
    if (!state) return;
    $('demo-credentials').hidden = !state.demo && $('demo-credentials').dataset.public !== 'true';
    updateUser(state.user);
    show('dashboard');
    await loadDashboard();
}
initialize().catch((error) => {
    show('dashboard');
    notify(error.message, true);
});
