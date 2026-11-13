// BP-12 / TD-13: picking SAP serials for serial-managed Goods Issue lines. The server re-checks everything; this only
// helps the user pick one SAP serial per unit.

const cellOf = (row) => row.querySelector('[data-serial-cell]');
const quantityOf = (row) => Number(row.querySelector('[data-quantity]')?.value || 0);
const pickedOf = (row) => [...row.querySelectorAll('[data-serial-input]')];
const needOf = (row) => (Number.isInteger(quantityOf(row)) ? quantityOf(row) : 0);

const setState = (row, requirement) => {
    const cell = cellOf(row);

    if (! cell) {
        return;
    }

    row.dataset.serialRequirement = requirement || '';
    cell.querySelector('[data-serial-idle]').classList.toggle('d-none', requirement !== '' && requirement !== undefined);
    cell.querySelector('[data-serial-none]').classList.toggle('d-none', requirement !== 'NON_SERIAL');
    cell.querySelector('[data-serial-unknown]').classList.toggle('d-none', requirement !== 'UNDETERMINED');
    cell.querySelector('[data-serial-picker]').classList.toggle('d-none', requirement !== 'SERIAL_REQUIRED');
};

const refresh = (row) => {
    const cell = cellOf(row);

    if (! cell || row.dataset.serialRequirement !== 'SERIAL_REQUIRED') {
        return;
    }

    const need = needOf(row);
    const inputs = pickedOf(row);
    const fractional = ! Number.isInteger(quantityOf(row));

    // Fewer units than serials: drop the last picks instead of silently keeping extras.
    inputs.slice(need).forEach((input) => input.remove());

    const picked = pickedOf(row);
    const button = cell.querySelector('[data-serial-open]');

    cell.querySelector('[data-serial-count]').textContent = picked.length;
    cell.querySelector('[data-serial-need]').textContent = need;
    button.classList.toggle('btn-outline-primary', picked.length !== need || need === 0);
    button.classList.toggle('btn-success', picked.length === need && need > 0);
    cell.querySelector('[data-serial-note]').textContent = fractional
        ? 'Qty harus bilangan bulat untuk item serial.'
        : '';

    const chips = cell.querySelector('[data-serial-chips]');

    chips.replaceChildren(...picked.map((input) => {
        const chip = document.createElement('span');
        chip.className = 'serial-chip';
        chip.textContent = input.dataset.label || `#${input.value}`;

        return chip;
    }));
};

const writePicks = (row, picks) => {
    const index = row.querySelector('[data-item-value]').name.match(/^lines\[(\d+)]/)[1];
    const holder = cellOf(row).querySelector('[data-serial-inputs]');

    holder.replaceChildren(...picks.map((pick) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `lines[${index}][serial_system_numbers][]`;
        input.value = pick.system_number;
        input.dataset.serialInput = '';
        input.dataset.label = pick.label;

        return input;
    }));
    refresh(row);
};

const dialog = () => document.querySelector('[data-serial-dialog]');

const state = { row: null, picks: new Map(), timer: null, controller: null };

const takenElsewhere = (row, itemId) => {
    const taken = new Set();

    row.closest('tbody').querySelectorAll('tr').forEach((other) => {
        if (other !== row && other.querySelector('[data-item-value]')?.value === itemId) {
            pickedOf(other).forEach((input) => taken.add(input.value));
        }
    });

    return taken;
};

const renderList = (rows) => {
    const need = needOf(state.row);
    const itemId = state.row.querySelector('[data-item-value]').value;
    const taken = takenElsewhere(state.row, itemId);
    const list = dialog().querySelector('[data-serial-list]');
    const full = state.picks.size >= need;

    list.replaceChildren(...rows.map((serial) => {
        const key = String(serial.system_number);
        const isTaken = taken.has(key);
        const label = document.createElement('label');
        label.className = 'serial-option' + (isTaken ? ' is-disabled' : '');

        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'form-check-input';
        box.value = key;
        box.checked = state.picks.has(key);
        box.disabled = isTaken || (full && ! box.checked);
        box.dataset.serialOption = '';
        box.dataset.label = serial.label;

        const text = document.createElement('span');
        text.textContent = serial.label + (isTaken ? ' — dipilih di baris lain' : '');
        label.append(box, text);

        return label;
    }));
};

const updateCounter = () => {
    const need = needOf(state.row);

    dialog().querySelector('[data-serial-dialog-count]').textContent = `${state.picks.size} dari ${need}`;
    dialog().querySelector('[data-serial-confirm]').disabled = state.picks.size === 0 && pickedOf(state.row).length === 0;
};

const showMessage = (message) => {
    const element = dialog().querySelector('[data-serial-message]');

    element.textContent = message || '';
    element.classList.toggle('d-none', ! message);
};

let lastRows = [];

const loadSerials = async () => {
    const row = state.row;
    const itemId = row.querySelector('[data-item-value]').value;
    const url = new URL(row.closest('[data-item-grid]').dataset.serialUrl, window.location.origin);

    url.searchParams.set('item_id', itemId);
    url.searchParams.set('q', dialog().querySelector('[data-serial-search]').value.trim());
    state.controller?.abort();
    state.controller = new AbortController();
    showMessage('Memuat serial dari SAP...');

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            redirect: 'error',
            signal: state.controller.signal,
        });
        const payload = await response.json().catch(() => ({}));

        if (! response.ok) {
            lastRows = [];
            renderList([]);
            showMessage(payload.message || 'Serial dari SAP tidak dapat dibaca. Hubungi tim SAP.');

            return;
        }

        lastRows = payload.data || [];
        renderList(lastRows);

        if (lastRows.length === 0) {
            showMessage('Belum ada serial yang tersedia dan lengkap di warehouse ini. Minta tim SAP melengkapi data serial sebelum baris ini diajukan.');
        } else if (payload.meta?.incomplete > 0) {
            showMessage(`${payload.meta.incomplete} serial belum lengkap identitasnya di SAP dan tidak ditampilkan. Minta tim SAP melengkapinya bila diperlukan.`);
        } else {
            showMessage('');
        }
    } catch (error) {
        if (error.name !== 'AbortError') {
            showMessage('Serial dari SAP tidak dapat dibaca. Coba lagi atau hubungi tim SAP.');
        }
    }
};

const openDialog = (row) => {
    const need = needOf(row);

    if (need < 1) {
        cellOf(row).querySelector('[data-serial-note]').textContent = 'Isi Qty (bilangan bulat) terlebih dahulu.';

        return;
    }

    state.row = row;
    state.picks = new Map(pickedOf(row).map((input) => [input.value, { system_number: input.value, label: input.dataset.label || `#${input.value}` }]));
    const target = dialog();

    target.querySelector('[data-serial-dialog-item]').textContent = row.querySelector('[data-item-label]').textContent;
    target.querySelector('[data-serial-dialog-warehouse]').textContent = target.dataset.warehouse;
    target.querySelector('[data-serial-search]').value = '';
    target.showModal();
    updateCounter();
    loadSerials();
};

document.addEventListener('click', (event) => {
    const open = event.target.closest('[data-serial-open]');

    if (open) {
        openDialog(open.closest('tr'));

        return;
    }

    if (event.target.closest('[data-serial-cancel]')) {
        dialog().close();

        return;
    }

    if (event.target.closest('[data-serial-confirm]')) {
        writePicks(state.row, [...state.picks.values()]);
        dialog().close();
    }
});

document.addEventListener('change', (event) => {
    if (event.target.matches('[data-serial-option]')) {
        const box = event.target;

        if (box.checked) {
            state.picks.set(box.value, { system_number: box.value, label: box.dataset.label });
        } else {
            state.picks.delete(box.value);
        }

        renderList(lastRows);
        updateCounter();

        return;
    }

    if (event.target.matches('[data-quantity]')) {
        const row = event.target.closest('tr');

        if (row && cellOf(row)) {
            refresh(row);
        }
    }
});

document.addEventListener('input', (event) => {
    if (! event.target.matches('[data-serial-search]')) {
        return;
    }

    window.clearTimeout(state.timer);
    state.timer = window.setTimeout(loadSerials, 300);
});

// Fired by the item picker when an item is chosen on a line.
document.addEventListener('item-selected', (event) => {
    const row = event.target.closest('tr');

    if (! row || ! cellOf(row)) {
        return;
    }

    cellOf(row).querySelector('[data-serial-inputs]').replaceChildren();
    setState(row, event.detail.serialRequirement || 'UNDETERMINED');
    refresh(row);
});

document.addEventListener('reset', (event) => {
    const form = event.target.closest('[data-sap-form]');

    form?.querySelectorAll('[data-serial-cell]').forEach((cell) => {
        const row = cell.closest('tr');

        cell.querySelector('[data-serial-inputs]').replaceChildren();
        setState(row, '');
    });
});

// Capture phase: runs before the generic form handler, which would otherwise lock the submit button.
document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-sap-form]');

    if (! form) {
        return;
    }

    let firstProblem = null;

    form.querySelectorAll('[data-item-grid] tbody tr').forEach((row) => {
        const cell = cellOf(row);

        if (! cell) {
            return;
        }

        const requirement = row.dataset.serialRequirement;
        const invalid = requirement === 'UNDETERMINED'
            || (requirement === 'SERIAL_REQUIRED' && (needOf(row) < 1 || pickedOf(row).length !== needOf(row)));

        cell.classList.toggle('serial-invalid', invalid);

        if (invalid) {
            cell.querySelector('[data-serial-note]').textContent = requirement === 'UNDETERMINED'
                ? 'Data serial item belum terbaca dari SAP. Hubungi tim SAP.'
                : `Pilih tepat ${needOf(row)} serial sebelum mengajukan.`;
            firstProblem ??= cell.querySelector('[data-serial-open]') || cell;
        }
    });

    if (firstProblem) {
        event.preventDefault();
        firstProblem.scrollIntoView({ block: 'center' });
        firstProblem.focus?.();
    }
}, true);

document.querySelectorAll('[data-item-grid] tbody tr').forEach((row) => {
    if (cellOf(row)) {
        setState(row, row.dataset.serialRequirement || '');
        refresh(row);
    }
});
