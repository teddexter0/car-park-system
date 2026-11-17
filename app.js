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
let dashboardRequest = 0;
let amsRequest = 0;
let sessionsRequest = 0;
let reportRequest = 0;
let assignment = null;
let dashboardZones = [];
const bayPages = new Map();
const baysPerPage = 56;
const bayStatuses = { available: 'Vacant', pending: 'Selected', occupied: 'Occupied', booked: 'Booked', unavailable: 'Unavailable' };

function notify(message, error = false, target = 'notice') {
    const element = $(target) || $('notice');
    element.textContent = message;
    element.hidden = !message;
    element.classList.toggle('error', error);
}

async function api(action, data, params = {}) {
    const requestGeneration = generation;
    const query = new URLSearchParams({ action, ...params });
    const options = data === undefined ? {} : {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(data),
    };
    const response = await fetch(`api.php?${query}`, options);
    const result = await response.json();
    if (requestGeneration !== generation) return null;
    if (!response.ok) {
        if (currentUser && (response.status === 401 || (response.status === 403 && result.error.startsWith('Your session token')))) {
            const state = await api('state');
            if (state && !state.user) {
                csrf = state.csrf;
                updateUser(null);
                show('login');
                if ($('action-dialog').open) $('action-dialog').close();
                notify('Your session ended. Sign in again.', true);
                return null;
            }
        }
        throw new Error(result.error || 'Request failed.');
    }
    if (result.csrf) csrf = result.csrf;
    return result;
}

function updateUser(user) {
    generation++;
    currentUser = user;
    $('navigation').hidden = !user;
    $('menu-toggle').hidden = !user;
    $('logout').hidden = !user;
    const canUseGate = ['security', 'admin'].includes(user?.role);
    $('student-bay-help').hidden = user?.role !== 'student';
    $('admin-bay-help').hidden = user?.role !== 'admin';
    document.querySelector('[data-screen="gate"]').hidden = !canUseGate;
    $('open-gate').hidden = !canUseGate;
    document.querySelector('[data-screen="admin"]').hidden = user?.role !== 'admin';
    $('identity').textContent = user ? `${user.name} � ${roles[user.role]}` : '';
    if (!user) {
        $('checkin-form').reset();
        $('ams-search').value = '';
        $('plate-search').value = '';
        $('history-search').value = '';
        for (const id of ['ams-users', 'checkin-zone', 'dashboard-stats', 'dashboard-zones', 'active-list',
            'zone-list', 'report-stats', 'report-zones', 'history-list', 'audit-list', 'account-list', 'action-fields', 'assignment-map']) {
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

function show(page) {
    screen = page;
    setMenu(false);
    all('.screen').forEach((section) => { section.hidden = section.id !== `${page}-screen`; });
    all('[data-screen]').forEach((button) => {
        if (button.dataset.screen === page) button.setAttribute('aria-current', 'page');
        else button.removeAttribute('aria-current');
    });
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
    const interactive = id === 'dashboard-zones' && ['student', 'admin'].includes(currentUser?.role);
    const signature = JSON.stringify([zones, [...bayPages]]);
    if (container.dataset.snapshot === signature && container.childElementCount) return;
    const before = new Map([...container.querySelectorAll('.zone-card')].map((card) =>
        [card.dataset.zoneId, Number(card.dataset.occupied)]));
    const focused = container.contains(document.activeElement) ? document.activeElement.id : '';
    container.dataset.snapshot = signature;
    container.innerHTML = zones.map((zone) => `
        <article class="panel zone-card" ${id === 'dashboard-zones' ? `id="zone-${Number(zone.id)}" tabindex="-1"` : ''} data-zone-id="${Number(zone.id)}" data-occupied="${Number(zone.occupied)}">
            <div class="section-heading"><div class="zone-title"><span class="zone-code">${zoneCode(zone.id)}</span><h3>${escape(zone.name)}</h3></div>
                <span class="pill ${Number(zone.available) === 0 ? 'warning' : ''}">${zone.available} available</span>
            </div>
            <p class="muted">${escape(zone.description)}</p>
            ${parkingTiles(zone, '', before.get(String(zone.id)), interactive)}
            <p>${zone.occupied} of ${zone.capacity} occupied</p>
            ${interactive && currentUser.role === 'student' ? `<p id="bay-notice-${Number(zone.id)}" class="notice" role="status" hidden></p>` : ''}
        </article>`).join('') || '<p>No active parking zones.</p>';
    if (focused) $(focused)?.focus({ preventScroll: true });
}

function renderCampus(zones) {
    for (const [id, code] of campusZones) {
        const area = $(`campus-zone-${code.toLowerCase()}`);
        const zone = zones.find((item) => Number(item.id) === id);
        const name = zone ? escape(zone.name) : 'Zone closed';
        const markup = zone
            ? `<a href="#zone-${id}" class="lot-link"><span class="zone-code">${code}</span><strong>${name}</strong><span class="lot-count ${Number(zone.available) === 0 ? 'lot-full' : ''}">${Number(zone.available)} available</span></a>`
            : `<div class="lot-link lot-unavailable"><span class="zone-code">${code}</span><strong>${name}</strong></div>`;
        if (area.dataset.snapshot !== markup || !area.childElementCount) {
            area.innerHTML = markup;
            area.dataset.snapshot = markup;
        }
    }
}

function parkingTiles(zone, phase, previousOccupied, interactive = false) {
    const zoneId = Number(zone.id);
    const capacity = Number(zone.capacity);
    const code = zoneCode(zoneId);
    const explicit = new Map(zone.bays.map((bay) => [bay.number, bay]));
    const pages = Math.ceil(capacity / baysPerPage);
    let page = interactive ? Math.min(bayPages.get(zoneId) || 0, pages - 1) : 0;
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
        const canClick = interactive && (status === 'available' || (currentUser.role === 'student' ? bay?.mine : status !== 'occupied'));
        const car = ['occupied', 'pending'].includes(status) ? '<svg class="icon" aria-hidden="true"><use href="#i-car"/></svg>' : '';
        const content = `${car}<span>${label}</span>${bay?.mine ? '<span class="tile-owner">You</span>' : ''}`;
        const attrs = `class="parking-tile tile-${status}${changed ? ' tile-change' : ''}" title="${escape(description)}"`;
        tiles.push(canClick
            ? `<button type="button" ${attrs} id="bay-${zoneId}-${number}" data-bay="${number}" data-zone-id="${zoneId}" data-status="${status}" aria-label="${escape(description)}">${content}</button>`
            : `<span ${attrs} role="img" aria-label="${escape(description)}"><span class="tile-label" aria-hidden="true">${content}</span></span>`);
    }
    const pager = interactive && pages > 1 ? `<div class="bay-pages">
        <button type="button" data-bay-page="${page - 1}" data-zone-id="${zoneId}" ${page === 0 ? 'disabled' : ''}>Previous bays</button>
        <small>${start}-${end} of ${capacity}</small>
        <button type="button" data-bay-page="${page + 1}" data-zone-id="${zoneId}" ${page === pages - 1 ? 'disabled' : ''}>Next bays</button>
        </div>` : '';
    return `<div class="parking-tiles">${tiles.join('')}</div>${pager}`;
}

function manageBay(zone, number, current) {
    const label = `${zoneCode(zone.id)}${String(number).padStart(2, '0')}`;
    const booking = zone.bays.find((bay) => bay.number === number)?.label || '';
    const options = ['available', 'booked', 'unavailable'].map((status) =>
        `<option value="${status}" ${status === (current === 'available' ? 'booked' : current) ? 'selected' : ''}>${bayStatuses[status]}</option>`);
    openAction(`Manage bay ${label}`, `<label>Status <select name="status">${options.join('')}</select></label>
        <label id="booking-field">Booking for <input name="booking_label" maxlength="100" placeholder="e.g. Directors' meeting" value="${escape(booking)}"></label>`,
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
    const message = !zone ? 'Choose a zone to preview the assignment.'
        : phase === 'confirmed' ? `Check-in confirmed in ${zone.name}. ${zone.available} available.`
        : phase === 'saving' ? `Recording the assignment in ${zone.name}.`
        : `Assigning to ${zone.name}. Confirm with Check in.`;
    if ($('assignment-state').textContent !== message) $('assignment-state').textContent = message;
    $('assignment-map').innerHTML = zone ? parkingTiles(zone, phase === 'saving' ? 'pending' : phase, assignment?.previous) : '';
    $('assignment-map').dataset.phase = phase;
}

async function loadDashboard(poll = false) {
    const request = ++dashboardRequest;
    const result = await api('dashboard', undefined, poll ? { poll: '1' } : {});
    if (!result || request !== dashboardRequest) return;
    if (screen === 'dashboard') {
        dashboardZones = result.zones;
        stats('dashboard-stats', [['Total capacity', result.capacity], ['Occupied spaces', result.occupied],
            ['Available spaces', result.available], ['Occupancy', `${result.percentage}%`]]);
        zoneCards('dashboard-zones', result.zones);
        renderCampus(result.zones);
        $('updated').textContent = `Updated ${new Date().toLocaleTimeString([], { timeZone: 'Africa/Nairobi', hour: '2-digit', minute: '2-digit', second: '2-digit' })} EAT`;
    }
    if (screen === 'gate') {
        gateZones = result.zones;
        setOptions($('checkin-zone'), availableZones(), 'Select a zone');
        renderAssignment();
    }
}

function availableZones(exclude = 0) {
    return gateZones.filter((zone) => Number(zone.id) !== exclude).map((zone) => ({
        id: zone.id,
        label: `${zoneCode(zone.id)} � ${zone.name} (${zone.available} available)`,
        disabled: Number(zone.available) === 0,
    }));
}

function optionMarkup(items, placeholder) {
    return `<option value="">${escape(placeholder)}</option>` + items.map(({ id, label, disabled }) =>
        `<option value="${id}" ${disabled ? 'disabled' : ''}>${escape(label)}</option>`).join('');
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
    const users = result.users.map((user) => ({ id: user.id, label: `${user.name} � ${user.institutional_id}` }));
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
    $('active-list').innerHTML = activeSessions.map((session) => record(
        `${escape(session.vehicle_plate)} <small>Session #${session.id}</small>`,
        `<small>${escape(session.driver)} � ${roles[session.driver_type]} � ${escape(session.zone)}</small>
         <small>Since ${timestamp(session.checkin_at)}</small>
         ${session.destination ? `<small>${escape(session.purpose)} � ${escape(session.destination)}</small>` : ''}
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
    if (section === 'report') return loadReport();
    const result = await api(section);
    if (!result) return;
    if (section === 'zones') {
        adminZones = result.zones;
        $('zone-list').innerHTML = adminZones.map((zone) => record(escape(zone.name),
            `<small>${escape(zone.description)}</small><small>${zone.occupied} / ${zone.capacity} occupied � ${Number(zone.active) ? 'Active' : 'Inactive'}</small>`,
            `<button data-zone="edit" data-id="${zone.id}">Edit</button><button data-zone="delete" data-id="${zone.id}">Delete</button>`,
        )).join('') || '<p>No zones. Add one to get started.</p>';
    } else if (section === 'audit') {
        $('audit-list').innerHTML = result.logs.map((log) => {
            const details = Object.entries(JSON.parse(log.metadata)).map(([key, value]) =>
                `${key.replaceAll('_', ' ')}: ${value}`).join(' � ');
            return record(escape(log.action.replaceAll('_', ' ')),
                `<small>${escape(log.actor || 'Unauthenticated')} � ${timestamp(log.created_at)} � ${escape(log.entity_type)} ${log.entity_id ?? ''}</small><small>${escape(details)}</small>`);
        }).join('') || '<p>No audit events.</p>';
    } else {
        $('account-list').innerHTML = result.accounts.map((account) => record(escape(account.name),
            `<small>${escape(account.email)} � ${roles[account.role]} � ${Number(account.active) ? 'Active' : 'Inactive'}</small>
             <small>${escape(account.institutional_id || '')}</small>`,
            account.id == currentUser.id ? '' :
                `<button data-account="${account.id}" data-active="${Number(account.active) ? 0 : 1}">${Number(account.active) ? 'Deactivate' : 'Reactivate'}</button>`,
        )).join('');
    }
}

async function loadReport() {
    const request = ++reportRequest;
    const result = await api('report', undefined, { q: $('history-search').value });
    if (!result || request !== reportRequest) return;
    stats('report-stats', [['Occupied', result.occupied], ['Available', result.available], ['Entries today', result.entries],
        ['Exits today', result.exits], ['Active visitors', result.active_visitors]]);
    zoneCards('report-zones', result.zones);
    $('history-list').innerHTML = result.sessions.map((session) => record(
        `${escape(session.vehicle_plate)} � #${session.id}`,
        `<small>${escape(session.driver)} � ${escape(session.zone)}</small>
         <small>Entry ${timestamp(session.checkin_at)} � ${session.checkout_at ? `Exit ${timestamp(session.checkout_at)}` : 'Active'}</small>`,
    )).join('') || '<p>No matching sessions.</p>';
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
    const reason = '<label>Correction reason <input name="reason" required maxlength="250"></label>';
    const transfer = operation === 'transfer';
    const checkout = operation === 'checkout';
    const fields = transfer
        ? `<p>Current zone: ${escape(session.zone)}</p><label>New zone <select name="zone_id" required>${optionMarkup(availableZones(Number(session.zone_id)), 'Select a zone')}</select></label>${reason}`
        : `<p>Confirm that ${plate} has departed.</p>${heldId}${checkout ? '' : reason}`;
    openAction(transfer ? `Transfer ${session.vehicle_plate}` : checkout ? 'Check out vehicle' : 'Correct missed checkout', fields,
        transfer ? 'Record transfer' : 'Record checkout',
        (form) => api(operation, { ...Object.fromEntries(new FormData(form)), id: session.id }));
}

function run(event, work, errorTarget = 'notice') {
    const button = event.submitter || event.currentTarget;
    if (button instanceof HTMLButtonElement) button.disabled = true;
    void Promise.resolve().then(work).catch((error) => notify(error.message, true, errorTarget)).finally(() => {
        if (button instanceof HTMLButtonElement) button.disabled = false;
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
    ['Sign in as a student', 'Use student@parking.test and the demo password ParkingDemo26!. This fictional account is linked to the simulated AMS.'],
    ['Check available spaces', 'Open Dashboard. Find Visitor Parking and note how many spaces are available.'],
    ['Manage a parking zone', 'Sign out and sign in as admin@parking.test with the same password. Add a temporary zone, edit its capacity, then delete it while it has no history.'],
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
        notify('');
        const result = await api('login', Object.fromEntries(new FormData(event.target)));
        if (!result) return;
        event.target.reset();
        updateUser(result.user);
        show('dashboard');
        await loadDashboard();
    });
});
$('logout').addEventListener('click', (event) => run(event, async () => {
    if (!await api('logout', {})) return;
    updateUser(null);
    show('login');
    notify('Signed out.');
}));
$('menu-toggle').addEventListener('click', () => setMenu($('navigation').dataset.open !== 'true'));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && $('navigation').dataset.open === 'true') {
        setMenu(false);
        $('menu-toggle').focus();
    }
});
all('[data-screen], [data-open-screen]').forEach((button) => button.addEventListener('click', (event) => run(event, async () => {
    notify('');
    show(button.dataset.screen || button.dataset.openScreen);
    $(`${screen}-title`).setAttribute('tabindex', '-1');
    $(`${screen}-title`).focus({ preventScroll: true });
    if (screen === 'dashboard') await loadDashboard();
    if (screen === 'gate') await Promise.all([loadDashboard(), loadAms(), loadActive()]);
    if (screen === 'admin') await loadAdmin('zones');
})));
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
    if (assignment?.phase === 'saving') return;
    run(event, async () => {
        const data = Object.fromEntries(new FormData(event.target));
        if (data.kind === 'visitor') {
            data.id_inspected = event.target.elements.id_inspected.checked;
            data.id_retained = event.target.elements.id_retained.checked;
        }
        const previous = Number(gateZones.find((zone) => String(zone.id) === data.zone_id)?.occupied);
        assignment = { id: data.zone_id, phase: 'saving', previous };
        renderAssignment();
        try {
            if (!await api('checkin', data)) return;
        } catch (error) {
            assignment = null;
            renderAssignment();
            throw error;
        }
        assignment.phase = 'confirmed';
        event.target.reset();
        toggleVisitorFields();
        $('ams-search').value = '';
        $('plate-search').value = '';
        notify('Vehicle checked in. Zone availability updated.');
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
onSearch('history-search', loadReport);
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
        zoneCards('dashboard-zones', dashboardZones);
        $(`zone-${zoneId}`).focus({ preventScroll: true });
        return;
    }
    const button = event.target.closest('[data-bay]');
    if (!button || !currentUser) return;
    const zone = dashboardZones.find((item) => Number(item.id) === Number(button.dataset.zoneId));
    const number = Number(button.dataset.bay);
    const current = button.dataset.status;
    if (currentUser.role === 'admin') {
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
        notify(result.return_id ? 'Checkout recorded. Return the visitor's physically retained ID.' : 'Parking record updated.');
        try {
            if (screen === 'gate') await Promise.all([loadDashboard(), loadActive()]);
            if (screen === 'admin') await loadAdmin();
            if (screen === 'dashboard') await loadDashboard();
        } catch (error) {
            notify(`The change was saved. Refresh failed: ${error.message}`, true);
        }
    }, 'action-error');
});
all('[data-close]').forEach((button) => button.addEventListener('click', () => $(button.dataset.close).close()));
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

setInterval(() => {
    if (!currentUser || screen !== 'dashboard' || document.hidden) return;
    loadDashboard(true).catch((error) => { $('updated').textContent = error.message; });
}, 10000);

async function initialize() {
    const state = await api('state');
    if (!state) return;
    $('demo-credentials').hidden = !state.demo && $('demo-credentials').dataset.public !== 'true';
    updateUser(state.user);
    show(state.user ? 'dashboard' : 'login');
    if (state.user) await loadDashboard();
}
initialize().catch((error) => {
    show('login');
    notify(error.message, true);
});
