import * as bootstrap from 'bootstrap';
import 'admin-lte';

window.bootstrap = bootstrap;

const enforceOperationalTheme = () => {
    document.documentElement.dataset.bsTheme = 'light';
    document.documentElement.style.colorScheme = 'light';
};

enforceOperationalTheme();
document.addEventListener('DOMContentLoaded', enforceOperationalTheme);

const itemComboboxStates = new WeakMap();

const itemComboboxState = (combobox) => {
    if (! itemComboboxStates.has(combobox)) {
        itemComboboxStates.set(combobox, {
            abortController: null,
            debounceTimer: null,
            hasMore: true,
            loading: false,
            page: 0,
            query: null,
        });
    }

    return itemComboboxStates.get(combobox);
};

const positionItemDropdown = (combobox) => {
    const toggle = combobox.querySelector('[data-item-toggle]');
    const dropdown = combobox.querySelector('[data-item-dropdown]');
    const bounds = toggle.getBoundingClientRect();
    const width = Math.min(Math.max(bounds.width, 360), window.innerWidth - 24);
    const left = Math.min(Math.max(bounds.left, 12), window.innerWidth - width - 12);

    dropdown.style.left = left + 'px';
    dropdown.style.top = (bounds.bottom + 4) + 'px';
    dropdown.style.width = width + 'px';
};

const closeItemCombobox = (combobox) => {
    combobox.querySelector('[data-item-dropdown]').classList.add('d-none');
    combobox.querySelector('[data-item-toggle]').setAttribute('aria-expanded', 'false');
};

const closeOtherItemComboboxes = (except = null) => {
    document.querySelectorAll('[data-item-combobox]').forEach((combobox) => {
        if (combobox !== except) {
            closeItemCombobox(combobox);
        }
    });
};

const renderItemMessage = (results, message, iconClass = null) => {
    results.replaceChildren();
    const state = document.createElement('div');
    state.className = 'item-combobox-state';

    if (iconClass) {
        const icon = document.createElement('i');
        icon.className = iconClass;
        icon.setAttribute('aria-hidden', 'true');
        state.append(icon);
    }

    const text = document.createElement('span');
    text.textContent = message;
    state.append(text);
    results.append(state);
};

const appendItemOptions = (combobox, items) => {
    const results = combobox.querySelector('[data-item-results]');

    items.forEach((item) => {
        const option = document.createElement('button');
        option.className = 'item-combobox-option';
        option.type = 'button';
        option.dataset.itemOption = '';
        option.dataset.id = item.id;
        option.dataset.code = item.item_code;
        option.dataset.name = item.item_name;
        option.dataset.uom = item.inventory_uom || '';
        option.dataset.available = item.available_stock ?? '';
        option.setAttribute('role', 'option');

        const code = document.createElement('span');
        code.className = 'item-combobox-code';
        code.textContent = item.item_code;

        const name = document.createElement('span');
        name.className = 'item-combobox-name';
        name.textContent = item.item_name;

        const uom = document.createElement('span');
        uom.className = 'item-combobox-uom';
        uom.textContent = item.available_stock === undefined
            ? (item.inventory_uom || '—')
            : `Available ${Number(item.available_stock).toLocaleString('id-ID', { maximumFractionDigits: 2 })} ${item.inventory_uom || ''}`;

        option.append(code, name, uom);
        results.append(option);
    });
};

const loadItemOptions = async (combobox, reset = false) => {
    const state = itemComboboxState(combobox);
    const results = combobox.querySelector('[data-item-results]');
    const moreButton = combobox.querySelector('[data-item-more]');
    const query = combobox.querySelector('[data-item-search]').value.trim();

    if (reset) {
        state.abortController?.abort();
        state.loading = false;
        state.page = 0;
        state.hasMore = true;
        state.query = query;
        results.replaceChildren();
    }

    if (state.loading || ! state.hasMore) {
        return;
    }

    state.loading = true;
    const abortController = new AbortController();
    state.abortController = abortController;
    moreButton.classList.add('d-none');

    if (state.page === 0) {
        renderItemMessage(results, 'Memuat item aktif...', 'fa-solid fa-spinner fa-spin');
    }

    try {
        const url = new URL(combobox.dataset.url, window.location.origin);
        url.searchParams.set('q', query);
        url.searchParams.set('page', state.page + 1);
        url.searchParams.set('per_page', 30);

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

        if (! response.ok) {
            throw new Error('Item lookup failed with status ' + response.status);
        }

        const payload = await response.json();

        if (state.page === 0) {
            results.replaceChildren();
        }

        appendItemOptions(combobox, payload.data || []);
        state.page = payload.meta?.current_page || state.page + 1;
        state.hasMore = Boolean(payload.meta?.has_more);

        if (! results.querySelector('[data-item-option]')) {
            renderItemMessage(results, 'Item aktif tidak ditemukan.', 'fa-regular fa-folder-open');
        }

        moreButton.classList.toggle('d-none', ! state.hasMore);
    } catch (error) {
        if (error.name !== 'AbortError') {
            renderItemMessage(results, 'Data item gagal dimuat. Coba kembali.', 'fa-solid fa-triangle-exclamation');
        }
    } finally {
        if (state.abortController === abortController) {
            state.loading = false;
        }
    }
};

const openItemCombobox = (combobox) => {
    closeOtherItemComboboxes(combobox);

    const dropdown = combobox.querySelector('[data-item-dropdown]');
    const toggle = combobox.querySelector('[data-item-toggle]');
    const search = combobox.querySelector('[data-item-search]');

    dropdown.classList.remove('d-none');
    toggle.setAttribute('aria-expanded', 'true');
    positionItemDropdown(combobox);
    search.focus();

    if (itemComboboxState(combobox).query === null) {
        loadItemOptions(combobox, true);
    }
};

const selectItemOption = (option) => {
    const combobox = option.closest('[data-item-combobox]');
    const row = combobox.closest('tr');
    const historyForm = combobox.closest('[data-sparepart-history-form]');

    combobox.querySelector('[data-item-value]').value = option.dataset.id;
    combobox.querySelector('[data-item-label]').textContent = option.dataset.code + ' — ' + option.dataset.name;
    combobox.querySelector('[data-item-toggle]').classList.remove('is-invalid');
    row.querySelector('[data-item-description]').textContent = option.dataset.name;
    row.querySelector('[data-item-uom]').textContent = option.dataset.uom || '—';
    const availableCell = row.querySelector('[data-item-available]');
    const quantityInput = row.querySelector('[data-quantity]');

    if (availableCell) {
        const available = Number(option.dataset.available);
        availableCell.textContent = Number.isFinite(available)
            ? available.toLocaleString('id-ID', { maximumFractionDigits: 2 })
            : '—';

        if (Number.isFinite(available)) {
            quantityInput.max = available;
        }
    }
    closeItemCombobox(combobox);

    if (historyForm) {
        refreshSparepartHistory(historyForm);
    }
};

const vehicleComboboxStates = new WeakMap();

const vehicleComboboxState = (combobox) => {
    if (! vehicleComboboxStates.has(combobox)) {
        vehicleComboboxStates.set(combobox, {
            abortController: null,
            debounceTimer: null,
            hasMore: false,
            loading: false,
            page: 0,
            query: '',
        });
    }

    return vehicleComboboxStates.get(combobox);
};

const positionVehicleDropdown = (combobox) => {
    const toggle = combobox.querySelector('[data-vehicle-toggle]');
    const dropdown = combobox.querySelector('[data-vehicle-dropdown]');
    const bounds = toggle.getBoundingClientRect();
    const width = Math.min(Math.max(bounds.width, 360), window.innerWidth - 24);
    const left = Math.min(Math.max(bounds.left, 12), window.innerWidth - width - 12);

    dropdown.style.left = left + 'px';
    dropdown.style.top = (bounds.bottom + 4) + 'px';
    dropdown.style.width = width + 'px';
};

const closeVehicleCombobox = (combobox) => {
    combobox.querySelector('[data-vehicle-dropdown]').classList.add('d-none');
    combobox.querySelector('[data-vehicle-toggle]').setAttribute('aria-expanded', 'false');
};

const closeOtherVehicleComboboxes = (except = null) => {
    document.querySelectorAll('[data-vehicle-combobox]').forEach((combobox) => {
        if (combobox !== except) {
            closeVehicleCombobox(combobox);
        }
    });
};

const appendVehicleOptions = (combobox, vehicles) => {
    const results = combobox.querySelector('[data-vehicle-results]');

    vehicles.forEach((vehicle) => {
        const option = document.createElement('button');
        option.className = 'item-combobox-option';
        option.type = 'button';
        option.dataset.vehicleOption = '';
        option.dataset.id = vehicle.id;
        option.dataset.code = vehicle.center_code;
        option.dataset.name = vehicle.center_name || vehicle.center_code;
        option.setAttribute('role', 'option');

        const code = document.createElement('span');
        code.className = 'item-combobox-code';
        code.textContent = vehicle.center_code;

        const name = document.createElement('span');
        name.className = 'item-combobox-name';
        name.textContent = vehicle.center_name || vehicle.center_code;

        const source = document.createElement('span');
        source.className = 'item-combobox-uom';
        source.textContent = 'SAP';

        option.append(code, name, source);
        results.append(option);
    });
};

const loadVehicleOptions = async (combobox, reset = false) => {
    const state = vehicleComboboxState(combobox);
    const results = combobox.querySelector('[data-vehicle-results]');
    const moreButton = combobox.querySelector('[data-vehicle-more]');
    const query = combobox.querySelector('[data-vehicle-search]').value.trim();

    if (reset) {
        state.abortController?.abort();
        state.loading = false;
        state.page = 0;
        state.hasMore = true;
        state.query = query;
        results.replaceChildren();
    }

    if (query.length < 2) {
        state.hasMore = false;
        moreButton.classList.add('d-none');
        renderItemMessage(results, 'Ketik minimal 2 karakter untuk mencari Nopol.', 'fa-solid fa-keyboard');

        return;
    }

    if (state.loading || ! state.hasMore) {
        return;
    }

    state.loading = true;
    const abortController = new AbortController();
    state.abortController = abortController;
    moreButton.classList.add('d-none');

    if (state.page === 0) {
        renderItemMessage(results, 'Mencari Nopol aktif di SAP...', 'fa-solid fa-spinner fa-spin');
    }

    try {
        const url = new URL(combobox.dataset.url, window.location.origin);
        url.searchParams.set('q', query);
        url.searchParams.set('page', state.page + 1);
        url.searchParams.set('per_page', 30);

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

        if (! response.ok) {
            throw new Error('Vehicle lookup failed with status ' + response.status);
        }

        const payload = await response.json();

        if (state.page === 0) {
            results.replaceChildren();
        }

        appendVehicleOptions(combobox, payload.data || []);
        state.page = payload.meta?.current_page || state.page + 1;
        state.hasMore = Boolean(payload.meta?.has_more);

        if (! results.querySelector('[data-vehicle-option]')) {
            renderItemMessage(results, 'Nopol aktif tidak ditemukan di SAP.', 'fa-regular fa-folder-open');
        }

        moreButton.classList.toggle('d-none', ! state.hasMore);
    } catch (error) {
        if (error.name !== 'AbortError') {
            renderItemMessage(results, 'Data Nopol gagal dimuat. Coba kembali.', 'fa-solid fa-triangle-exclamation');
        }
    } finally {
        if (state.abortController === abortController) {
            state.loading = false;
        }
    }
};

const openVehicleCombobox = (combobox) => {
    closeOtherItemComboboxes();
    closeOtherVehicleComboboxes(combobox);

    const dropdown = combobox.querySelector('[data-vehicle-dropdown]');
    const toggle = combobox.querySelector('[data-vehicle-toggle]');
    const search = combobox.querySelector('[data-vehicle-search]');

    dropdown.classList.remove('d-none');
    toggle.setAttribute('aria-expanded', 'true');
    positionVehicleDropdown(combobox);
    search.focus();
};

const selectVehicleOption = (option) => {
    const combobox = option.closest('[data-vehicle-combobox]');
    const value = combobox.querySelector('[data-vehicle-value]');

    value.value = option.dataset.id;
    combobox.querySelector('[data-vehicle-label]').textContent = option.dataset.code + ' — ' + option.dataset.name;
    combobox.querySelector('[data-vehicle-toggle]').classList.remove('is-invalid');
    closeVehicleCombobox(combobox);
    value.dispatchEvent(new Event('change', { bubbles: true }));
};

const updateGridSummary = (grid) => {
    const rows = [...grid.querySelectorAll('tbody tr')];
    const quantity = rows.reduce((total, row) => total + Number(row.querySelector('[data-quantity]')?.value || 0), 0);
    const form = grid.closest('form');

    form?.querySelectorAll('[data-line-count]').forEach((element) => { element.textContent = rows.length; });
    form?.querySelectorAll('[data-total-quantity]').forEach((element) => { element.textContent = quantity.toLocaleString('id-ID'); });
};

const sparepartHistoryStates = new WeakMap();

const sparepartHistoryState = (form) => {
    if (! sparepartHistoryStates.has(form)) {
        sparepartHistoryStates.set(form, { abortController: null });
    }

    return sparepartHistoryStates.get(form);
};

const appendHistoryCell = (row, value, className = '') => {
    const cell = document.createElement('td');
    cell.className = className;
    cell.textContent = value;
    row.append(cell);
};

const historyNumber = (value, maximumFractionDigits = 0) => Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits });

const historyDate = (value) => {
    if (! value) {
        return '—';
    }

    const [year, month, day] = String(value).slice(0, 10).split('-');

    return day && month && year ? `${day}/${month}/${year}` : String(value);
};

const renderHistoryMessage = (body, message, columnCount = 5) => {
    body.replaceChildren();
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = columnCount;
    cell.className = 'text-center text-secondary py-4';
    cell.textContent = message;
    row.append(cell);
    body.append(row);
};

const renderUsageHistory = (body, rows) => {
    body.replaceChildren();

    if (! rows.length) {
        renderHistoryMessage(body, 'Pilih Nopol dan item untuk melihat riwayat pemakaian.', 7);

        return;
    }

    rows.forEach((entry) => {
        const row = document.createElement('tr');
        appendHistoryCell(row, `${entry.item_code || '—'} — ${entry.item_name || '—'}`);
        appendHistoryCell(row, historyDate(entry.last_goods_issue_date), 'text-nowrap');
        appendHistoryCell(row, entry.fpk || '—', 'mono');
        appendHistoryCell(row, entry.status === 'FOUND' ? historyNumber(entry.quantity, 2) : '—', 'text-end mono');
        appendHistoryCell(row, entry.status === 'FOUND' ? 'Rp ' + historyNumber(entry.expense_amount) : '—', 'text-end mono fw-semibold');
        appendHistoryCell(row, entry.days_in_use === null ? '—' : `${historyNumber(entry.days_in_use)} hari`, 'text-end');
        appendHistoryCell(row, entry.status === 'FOUND' ? 'Ditemukan' : (entry.status === 'NOT_FOUND' ? 'Belum pernah GI' : 'Tidak tersedia'));
        body.append(row);
    });
};

const refreshSparepartHistory = async (form) => {
    const section = form.querySelector('[data-sparepart-history]');

    if (! section) {
        return;
    }

    const itemIds = [...new Set([...form.querySelectorAll('[data-item-value]')]
        .map((field) => field.value)
        .filter(Boolean))];
    const vehicleId = form.querySelector('[name="vehicle_cost_center_id"]')?.value || '';
    const historyBody = section.querySelector('[data-sparepart-usage-history]');
    const warning = section.querySelector('[data-sparepart-history-warning]');

    if (! vehicleId) {
        renderHistoryMessage(historyBody, 'Pilih Nopol untuk melihat riwayat pemakaian item.', 7);
        section.querySelector('[data-sparepart-vehicle-label]').textContent = 'Pilih Nopol';
    } else if (! itemIds.length) {
        renderHistoryMessage(historyBody, 'Pilih minimal satu item untuk melihat riwayat pemakaian.', 7);
        section.querySelector('[data-sparepart-vehicle-label]').textContent = 'Nopol dipilih';
    } else {
        renderHistoryMessage(historyBody, 'Memuat Goods Issue terakhir untuk seluruh item...', 7);
    }

    warning.classList.add('d-none');

    if (! itemIds.length || ! vehicleId) {
        return;
    }

    const state = sparepartHistoryState(form);
    state.abortController?.abort();
    const abortController = new AbortController();
    state.abortController = abortController;

    try {
        const url = new URL(form.dataset.sparepartHistoryUrl, window.location.origin);

        itemIds.forEach((itemId) => url.searchParams.append('item_ids[]', itemId));
        url.searchParams.set('vehicle_cost_center_id', vehicleId);

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

        if (! response.ok) {
            throw new Error('Sparepart history failed with status ' + response.status);
        }

        const payload = await response.json();

        section.querySelector('[data-sparepart-vehicle-label]').textContent = payload.vehicle?.code || 'Nopol';
        renderUsageHistory(historyBody, payload.histories || []);

        if (payload.warnings?.length) {
            warning.textContent = payload.warnings.join(' ');
            warning.classList.remove('d-none');
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        renderHistoryMessage(historyBody, 'Riwayat pemakaian belum dapat dimuat dari SAP.', 7);

        warning.textContent = 'Koneksi histori SAP gagal. Form PR tetap dapat dilanjutkan.';
        warning.classList.remove('d-none');
    }
};

document.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-add-line]');
    const removeButton = event.target.closest('[data-remove-line]');
    const itemToggle = event.target.closest('[data-item-toggle]');
    const itemOption = event.target.closest('[data-item-option]');
    const moreButton = event.target.closest('[data-item-more]');
    const vehicleToggle = event.target.closest('[data-vehicle-toggle]');
    const vehicleOption = event.target.closest('[data-vehicle-option]');
    const vehicleMoreButton = event.target.closest('[data-vehicle-more]');

    if (vehicleToggle) {
        const combobox = vehicleToggle.closest('[data-vehicle-combobox]');
        const isOpen = vehicleToggle.getAttribute('aria-expanded') === 'true';

        if (isOpen) {
            closeVehicleCombobox(combobox);
        } else {
            openVehicleCombobox(combobox);
        }

        return;
    }

    if (vehicleOption) {
        selectVehicleOption(vehicleOption);

        return;
    }

    if (vehicleMoreButton) {
        loadVehicleOptions(vehicleMoreButton.closest('[data-vehicle-combobox]'));

        return;
    }

    if (itemToggle) {
        const combobox = itemToggle.closest('[data-item-combobox]');
        const isOpen = itemToggle.getAttribute('aria-expanded') === 'true';

        if (isOpen) {
            closeItemCombobox(combobox);
        } else {
            openItemCombobox(combobox);
        }

        return;
    }

    if (itemOption) {
        selectItemOption(itemOption);

        return;
    }

    if (moreButton) {
        loadItemOptions(moreButton.closest('[data-item-combobox]'));

        return;
    }

    if (addButton) {
        const form = addButton.closest('form');
        const grid = form.querySelector('[data-item-grid]');
        const template = form.querySelector('template[data-line-template]');
        const usedIndexes = [...grid.querySelectorAll('[name^="lines["]')]
            .map((field) => Number(field.name.match(/^lines\[(\d+)]/)?.[1] ?? -1));
        const nextIndex = Math.max(-1, ...usedIndexes) + 1;
        const fragment = template.content.cloneNode(true);

        fragment.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace('__INDEX__', nextIndex);
        });
        grid.querySelector('tbody').append(fragment);
        updateGridSummary(grid);
    }

    if (removeButton) {
        const grid = removeButton.closest('[data-item-grid]');
        const historyForm = removeButton.closest('[data-sparepart-history-form]');

        if (grid.querySelectorAll('tbody tr').length > 1) {
            removeButton.closest('tr').remove();
            updateGridSummary(grid);

            if (historyForm) {
                refreshSparepartHistory(historyForm);
            }
        }
    }

    if (! event.target.closest('[data-item-combobox]')) {
        closeOtherItemComboboxes();
    }

    if (! event.target.closest('[data-vehicle-combobox]')) {
        closeOtherVehicleComboboxes();
    }
});

document.addEventListener('change', (event) => {
    if (event.target.matches('[data-quantity]')) {
        updateGridSummary(event.target.closest('[data-item-grid]'));
    }

    if (event.target.matches('[data-sparepart-history-form] [name="vehicle_cost_center_id"]')) {
        refreshSparepartHistory(event.target.closest('[data-sparepart-history-form]'));
    }
});

document.addEventListener('input', (event) => {
    if (event.target.matches('[data-vehicle-search]')) {
        const combobox = event.target.closest('[data-vehicle-combobox]');
        const state = vehicleComboboxState(combobox);

        window.clearTimeout(state.debounceTimer);
        state.debounceTimer = window.setTimeout(() => loadVehicleOptions(combobox, true), 250);

        return;
    }

    if (! event.target.matches('[data-item-search]')) {
        return;
    }

    const combobox = event.target.closest('[data-item-combobox]');
    const state = itemComboboxState(combobox);

    window.clearTimeout(state.debounceTimer);
    state.debounceTimer = window.setTimeout(() => loadItemOptions(combobox, true), 250);
});

document.addEventListener('scroll', (event) => {
    const vehicleResults = event.target.closest?.('[data-vehicle-results]');

    if (vehicleResults && vehicleResults.scrollHeight - vehicleResults.scrollTop - vehicleResults.clientHeight <= 48) {
        loadVehicleOptions(vehicleResults.closest('[data-vehicle-combobox]'));

        return;
    }

    const results = event.target.closest?.('[data-item-results]');

    if (! results || results.scrollHeight - results.scrollTop - results.clientHeight > 48) {
        return;
    }

    loadItemOptions(results.closest('[data-item-combobox]'));
}, true);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeOtherItemComboboxes();
        closeOtherVehicleComboboxes();
    }

    if (event.key === 'ArrowDown' && event.target.matches('[data-item-search]')) {
        event.preventDefault();
        event.target.closest('[data-item-combobox]').querySelector('[data-item-option]')?.focus();
    }

    if (event.key === 'ArrowDown' && event.target.matches('[data-vehicle-search]')) {
        event.preventDefault();
        event.target.closest('[data-vehicle-combobox]').querySelector('[data-vehicle-option]')?.focus();
    }
});

window.addEventListener('resize', () => {
    closeOtherItemComboboxes();
    closeOtherVehicleComboboxes();
});

document.querySelectorAll('[data-user-registration]').forEach((form) => {
    const roleSelect = form.querySelector('[data-role-select]');
    const roleDescription = form.querySelector('[data-role-description]');
    const updateRoleDescription = () => {
        roleDescription.textContent = roleSelect.selectedOptions[0]?.dataset.description
            || 'Pilih role sesuai tanggung jawab user.';
    };

    roleSelect.addEventListener('change', updateRoleDescription);
    updateRoleDescription();
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-sap-form]');

    if (! form) {
        return;
    }

    let firstInvalidToggle = null;

    form.querySelectorAll('[data-item-combobox]').forEach((combobox) => {
        const toggle = combobox.querySelector('[data-item-toggle]');
        const isValid = combobox.querySelector('[data-item-value]').value !== '';

        toggle.classList.toggle('is-invalid', ! isValid);
        firstInvalidToggle ??= isValid ? null : toggle;
    });

    form.querySelectorAll('[data-vehicle-combobox]').forEach((combobox) => {
        const toggle = combobox.querySelector('[data-vehicle-toggle]');
        const isValid = combobox.querySelector('[data-vehicle-value]').value !== '';

        toggle.classList.toggle('is-invalid', ! isValid);
        firstInvalidToggle ??= isValid ? null : toggle;
    });

    if (firstInvalidToggle) {
        event.preventDefault();
        firstInvalidToggle.focus();

        return;
    }

    const submitButton = form.querySelector('[data-sap-submit]');

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Mengirim ke SAP...';
    }
});

document.addEventListener('reset', (event) => {
    const form = event.target.closest('[data-sap-form]');

    if (! form) {
        return;
    }

    window.setTimeout(() => {
        form.querySelectorAll('[data-item-combobox]').forEach((combobox) => {
            combobox.querySelector('[data-item-value]').value = '';
            combobox.querySelector('[data-item-label]').textContent = 'Pilih atau cari item';
            combobox.querySelector('[data-item-toggle]').classList.remove('is-invalid');
            combobox.closest('tr').querySelector('[data-item-description]').textContent = '—';
            combobox.closest('tr').querySelector('[data-item-uom]').textContent = '—';
            const availableCell = combobox.closest('tr').querySelector('[data-item-available]');

            if (availableCell) {
                availableCell.textContent = '—';
                combobox.closest('tr').querySelector('[data-quantity]').removeAttribute('max');
            }
            closeItemCombobox(combobox);
        });
        form.querySelectorAll('[data-vehicle-combobox]').forEach((combobox) => {
            combobox.querySelector('[data-vehicle-value]').value = '';
            combobox.querySelector('[data-vehicle-label]').textContent = 'Ketik minimal 2 karakter untuk mencari Nopol';
            combobox.querySelector('[data-vehicle-search]').value = '';
            combobox.querySelector('[data-vehicle-toggle]').classList.remove('is-invalid');
            renderItemMessage(combobox.querySelector('[data-vehicle-results]'), 'Ketik minimal 2 karakter untuk mencari Nopol.', 'fa-solid fa-keyboard');
            closeVehicleCombobox(combobox);
        });
        form.querySelectorAll('[data-item-grid]').forEach(updateGridSummary);

        if (form.matches('[data-sparepart-history-form]')) {
            delete form.dataset.sparepartHistoryItemId;
            refreshSparepartHistory(form);
        }
    });
});

document.querySelectorAll('[data-item-grid]').forEach(updateGridSummary);
document.querySelectorAll('[data-sparepart-history-form]').forEach(refreshSparepartHistory);

const paymentNumber = (value) => Number(value || 0);
const formatPaymentNumber = (value) => paymentNumber(value).toLocaleString('id-ID', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const setPaymentMessage = (form, message, type = 'success') => {
    const result = form.querySelector('[data-payment-result]');

    result.textContent = message;
    result.classList.remove('d-none', 'alert-success', 'alert-danger');
    result.classList.add(type === 'danger' ? 'alert-danger' : 'alert-success');
    result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

const paymentCell = (value, className = '') => {
    const cell = document.createElement('td');
    cell.className = className;
    cell.textContent = value;

    return cell;
};

const removePaymentEmptyRow = (body) => body.querySelector('[data-empty-row]')?.remove();

const addPaymentEmptyRow = (body, columns, icon, message) => {
    if (body.querySelector('tr')) {
        return;
    }

    const row = document.createElement('tr');
    row.dataset.emptyRow = '';
    const cell = document.createElement('td');
    cell.colSpan = columns;
    const state = document.createElement('div');
    state.className = 'empty-state py-4';
    const stateIcon = document.createElement('i');
    stateIcon.className = icon;
    state.append(stateIcon, document.createTextNode(message));
    cell.append(state);
    row.append(cell);
    body.append(row);
};

const updatePaymentSummary = (form) => {
    const type = form.querySelector('[data-payment-type]').value;
    let total = 0;

    if (type === 'VENDOR') {
        form.querySelectorAll('[data-invoice-line]').forEach((row) => {
            total += paymentNumber(row.dataset.paymentAmount);
        });
    } else if (type === 'ACCOUNT') {
        form.querySelectorAll('[data-account-line]').forEach((row) => {
            total += paymentNumber(row.dataset.paymentAmount);
        });
    } else {
        total = paymentNumber(form.querySelector('[data-customer-payment-amount]').value);
    }

    const bankCharge = paymentNumber(form.querySelector('[data-bank-charge]').value);
    form.querySelector('[data-total-payment]').textContent = formatPaymentNumber(total);
    form.querySelector('[data-grand-total]').textContent = formatPaymentNumber(total + bankCharge);
};

const switchPaymentPane = (form, paneName) => {
    form.querySelectorAll('[data-payment-pane]').forEach((pane) => {
        pane.classList.toggle('d-none', pane.dataset.paymentPane !== paneName);
    });
    form.querySelectorAll('[data-payment-tab]').forEach((tab) => {
        tab.classList.toggle('active', tab.dataset.paymentTab === paneName);
    });
};

const synchronizePaymentType = (form) => {
    const type = form.querySelector('[data-payment-type]').value;
    const requiresBusinessPartner = type !== 'ACCOUNT';

    form.querySelectorAll('[data-bp-fields]').forEach((group) => {
        group.classList.toggle('d-none', ! requiresBusinessPartner);
        group.querySelectorAll('input').forEach((input) => { input.required = requiresBusinessPartner; });
    });
    form.querySelector('[data-customer-amount-field]').classList.toggle('d-none', type !== 'CUSTOMER');
    form.querySelector('.payment-detail-tabs').closest('.transaction-body').classList.toggle('d-none', type === 'CUSTOMER');

    if (type === 'ACCOUNT') {
        switchPaymentPane(form, 'account');
    } else {
        switchPaymentPane(form, 'invoice');
        form.querySelector('[data-payment-pane="invoice"]').classList.toggle('d-none', type === 'CUSTOMER');
    }

    updatePaymentSummary(form);
};

const synchronizeDocumentRate = (form) => {
    const currency = form.querySelector('[data-payment-currency]').value;
    const rate = form.querySelector('[data-document-rate]');
    rate.disabled = currency === 'IDR';
    rate.value = currency === 'IDR' ? '1' : rate.value;
};

const synchronizePaymentMeans = (form) => {
    const means = form.querySelector('[data-payment-means]').value;
    form.querySelector('[data-bank-fields]').classList.toggle('d-none', means !== 'BANK TRANSFER' && means !== 'CHECK');
    form.querySelector('[data-check-fields]').classList.toggle('d-none', means !== 'CHECK');
};

const addInvoicePaymentLine = (form) => {
    const source = form.querySelector('[data-invoice-source]');
    const selected = source.selectedOptions[0];
    const type = form.querySelector('[data-invoice-type]').value;
    const paymentAmount = paymentNumber(form.querySelector('[data-invoice-payment-amount]').value);
    const cashDiscount = paymentNumber(form.querySelector('[data-invoice-cash-discount]').value);
    const balance = paymentNumber(selected?.dataset.balance);

    if (! selected?.value) {
        setPaymentMessage(form, 'Pilih invoice SAP yang akan dibayar.', 'danger');
        return;
    }

    if (type !== selected.dataset.documentType) {
        setPaymentMessage(form, 'Invoice Type tidak sesuai dengan dokumen SAP yang dipilih.', 'danger');
        return;
    }

    if (form.querySelector(`[data-invoice-line][data-entry="${CSS.escape(selected.value)}"][data-invoice-type="${CSS.escape(type)}"]`)) {
        setPaymentMessage(form, 'Invoice tersebut sudah ada dalam Payment Request ini.', 'danger');
        return;
    }

    if (selected.dataset.currency !== form.querySelector('[data-payment-currency]').value) {
        setPaymentMessage(form, 'Document Currency dan Invoice Currency harus sama.', 'danger');
        return;
    }

    if (paymentAmount === 0 || Math.abs(paymentAmount) + Math.abs(cashDiscount) > Math.abs(balance)) {
        setPaymentMessage(form, 'Payment Amount dan Cash Discount tidak boleh melebihi sisa balance invoice.', 'danger');
        return;
    }

    const body = form.querySelector('[data-invoice-lines]');
    removePaymentEmptyRow(body);
    const row = document.createElement('tr');
    row.dataset.invoiceLine = '';
    row.dataset.entry = selected.value;
    row.dataset.invoiceType = type;
    row.dataset.paymentAmount = paymentAmount;
    row.append(
        paymentCell(String(body.querySelectorAll('[data-invoice-line]').length + 1)),
        paymentCell(type),
        paymentCell(selected.dataset.documentNo || selected.value, 'mono'),
        paymentCell(selected.dataset.vendorReference || '—'),
        paymentCell(selected.dataset.currency || 'IDR', 'mono'),
        paymentCell(formatPaymentNumber(selected.dataset.invoiceAmount), 'text-end mono'),
        paymentCell(formatPaymentNumber(selected.dataset.withholdingTax), 'text-end mono'),
        paymentCell(formatPaymentNumber(balance), 'text-end mono'),
        paymentCell(formatPaymentNumber(cashDiscount), 'text-end mono'),
        paymentCell(formatPaymentNumber(paymentAmount), 'text-end mono'),
    );
    const action = document.createElement('td');
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-sm btn-outline-danger';
    remove.dataset.removePaymentLine = '';
    remove.setAttribute('aria-label', 'Hapus invoice');
    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-trash';
    remove.append(icon);
    action.append(remove);
    row.append(action);
    body.append(row);

    form.querySelector('#payment-bp-code').value = selected.dataset.vendorCode || form.querySelector('#payment-bp-code').value;
    form.querySelector('#payment-bp-name').value = selected.dataset.vendorName || form.querySelector('#payment-bp-name').value;
    source.value = '';
    form.querySelector('[data-invoice-payment-amount]').value = '0';
    form.querySelector('[data-invoice-cash-discount]').value = '0';
    updatePaymentSummary(form);
    setPaymentMessage(form, 'Invoice ditambahkan dan lolos validasi awal.');
};

const addAccountPaymentLine = (form) => {
    const code = form.querySelector('[data-account-code]').value.trim();
    const name = form.querySelector('[data-account-name]').value.trim();
    const amount = paymentNumber(form.querySelector('[data-account-amount]').value);

    if (! code || ! name || amount <= 0) {
        setPaymentMessage(form, 'Account No, Account Name, dan Payment Amount wajib diisi.', 'danger');
        return;
    }

    const body = form.querySelector('[data-account-lines]');
    removePaymentEmptyRow(body);
    const row = document.createElement('tr');
    row.dataset.accountLine = '';
    row.dataset.paymentAmount = amount;
    row.append(
        paymentCell(String(body.querySelectorAll('[data-account-line]').length + 1)),
        paymentCell(code, 'mono'),
        paymentCell(name),
        paymentCell(form.querySelector('[data-account-department]').value || '—', 'mono'),
        paymentCell(form.querySelector('[data-account-pool]').value || '—', 'mono'),
        paymentCell(form.querySelector('[data-account-vehicle]').value || '—', 'mono'),
        paymentCell(form.querySelector('[data-account-project]').value || '—', 'mono'),
        paymentCell(form.querySelector('[data-account-remarks]').value || '—'),
        paymentCell(formatPaymentNumber(amount), 'text-end mono'),
    );
    const action = document.createElement('td');
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-sm btn-outline-danger';
    remove.dataset.removePaymentLine = '';
    remove.setAttribute('aria-label', 'Hapus account');
    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-trash';
    remove.append(icon);
    action.append(remove);
    row.append(action);
    body.append(row);

    ['code', 'name', 'remarks', 'amount', 'department', 'pool', 'vehicle', 'project', 'dimension-4', 'dimension-5'].forEach((field) => {
        const input = form.querySelector(`[data-account-${field}]`);
        input.value = field === 'amount' ? '0' : '';
    });
    updatePaymentSummary(form);
    setPaymentMessage(form, 'Baris account ditambahkan dan lolos validasi awal.');
};

const paymentRequestIsComplete = (form) => {
    const type = form.querySelector('[data-payment-type]').value;

    if (! form.checkValidity()) {
        form.reportValidity();
        return false;
    }

    if (type === 'VENDOR' && ! form.querySelector('[data-invoice-line]')) {
        setPaymentMessage(form, 'Tambahkan minimal satu invoice sebelum submit.', 'danger');
        return false;
    }

    if (type === 'ACCOUNT' && ! form.querySelector('[data-account-line]')) {
        setPaymentMessage(form, 'Tambahkan minimal satu baris account sebelum submit.', 'danger');
        return false;
    }

    if (type === 'CUSTOMER' && paymentNumber(form.querySelector('[data-customer-payment-amount]').value) <= 0) {
        setPaymentMessage(form, 'Payment Amount customer harus lebih besar dari nol.', 'danger');
        return false;
    }

    return true;
};

document.querySelectorAll('[data-payment-request-form]').forEach((form) => {
    synchronizePaymentType(form);
    synchronizeDocumentRate(form);
    synchronizePaymentMeans(form);

    form.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-payment-tab]');

        if (tab) {
            switchPaymentPane(form, tab.dataset.paymentTab);
        }

        if (event.target.closest('[data-add-invoice-line]')) {
            addInvoicePaymentLine(form);
        }

        if (event.target.closest('[data-add-account-line]')) {
            addAccountPaymentLine(form);
        }

        const remove = event.target.closest('[data-remove-payment-line]');

        if (remove) {
            const row = remove.closest('tr');
            const body = row.parentElement;
            const isInvoice = row.hasAttribute('data-invoice-line');
            row.remove();
            addPaymentEmptyRow(
                body,
                isInvoice ? 11 : 10,
                isInvoice ? 'fa-regular fa-file-lines' : 'fa-solid fa-table-list',
                isInvoice ? 'Pilih invoice SAP lalu tambahkan ke request.' : 'Tambahkan baris account untuk pembayaran non-invoice.',
            );
            updatePaymentSummary(form);
        }

        if (event.target.closest('[data-save-draft]')) {
            setPaymentMessage(form, 'Struktur draft valid. Penyimpanan ke UDO SAP belum diaktifkan.');
        }
    });

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-payment-type]')) {
            synchronizePaymentType(form);
        }

        if (event.target.matches('[data-payment-currency]')) {
            synchronizeDocumentRate(form);
        }

        if (event.target.matches('[data-payment-means]')) {
            synchronizePaymentMeans(form);
        }

        if (event.target.matches('[data-invoice-source]')) {
            const selected = event.target.selectedOptions[0];

            if (selected?.value) {
                form.querySelector('[data-invoice-type]').value = selected.dataset.documentType;
                form.querySelector('[data-invoice-payment-amount]').value = selected.dataset.balance || '0';
                form.querySelector('[data-payment-currency]').value = selected.dataset.currency || 'IDR';
                form.querySelector('#payment-bp-code').value = selected.dataset.vendorCode || '';
                form.querySelector('#payment-bp-name').value = selected.dataset.vendorName || '';
                synchronizeDocumentRate(form);

                const amount = form.querySelector('[data-invoice-payment-amount]');
                if (selected.dataset.documentType === 'APCN') {
                    amount.removeAttribute('min');
                } else {
                    amount.min = '0';
                }
            }
        }
    });

    form.addEventListener('input', (event) => {
        if (event.target.matches('[data-bank-charge], [data-customer-payment-amount]')) {
            updatePaymentSummary(form);
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        if (paymentRequestIsComplete(form)) {
            setPaymentMessage(form, 'Payment Request siap disubmit ke approval. Tidak ada data yang dikirim ke SAP dalam mode rancangan.');
        }
    });

    form.addEventListener('reset', () => {
        window.setTimeout(() => {
            form.querySelector('[data-invoice-lines]').replaceChildren();
            form.querySelector('[data-account-lines]').replaceChildren();
            addPaymentEmptyRow(form.querySelector('[data-invoice-lines]'), 11, 'fa-regular fa-file-lines', 'Pilih invoice SAP lalu tambahkan ke request.');
            addPaymentEmptyRow(form.querySelector('[data-account-lines]'), 10, 'fa-solid fa-table-list', 'Tambahkan baris account untuk pembayaran non-invoice.');
            form.querySelector('[data-payment-result]').classList.add('d-none');
            synchronizePaymentType(form);
            synchronizeDocumentRate(form);
            synchronizePaymentMeans(form);
            updatePaymentSummary(form);
        });
    });
});
