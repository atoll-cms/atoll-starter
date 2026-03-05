const DEFAULTS = {
  selector: 'table',
  searchable: true,
  pageSize: 10,
  emptyMessage: 'Keine Eintraege gefunden.',
  searchPlaceholder: 'Tabelle filtern...'
};

const STYLE_ID = 'atoll-table-enhancer-style';

const normalizeText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();

const parseNumberish = (value) => {
  const compact = normalizeText(value).replace(/\s/g, '').replace(',', '.').replace(/[^0-9.\-]/g, '');
  if (compact === '' || compact === '-' || compact === '.') return null;
  const parsed = Number(compact);
  return Number.isFinite(parsed) ? parsed : null;
};

const parseDateish = (value) => {
  const raw = normalizeText(value);
  if (raw === '') return null;
  const parsed = Date.parse(raw);
  return Number.isFinite(parsed) ? parsed : null;
};

const compareValues = (left, right) => {
  const leftNumber = parseNumberish(left);
  const rightNumber = parseNumberish(right);
  if (leftNumber !== null && rightNumber !== null) {
    return leftNumber - rightNumber;
  }

  const leftDate = parseDateish(left);
  const rightDate = parseDateish(right);
  if (leftDate !== null && rightDate !== null) {
    return leftDate - rightDate;
  }

  return normalizeText(left).localeCompare(normalizeText(right), undefined, {
    sensitivity: 'base',
    numeric: true
  });
};

const ensureStyle = () => {
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = `
    .atoll-table-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin: 0.75rem 0;
      flex-wrap: wrap;
    }
    .atoll-table-toolbar input[type="search"] {
      min-width: 220px;
      flex: 1 1 260px;
      padding: 0.55rem 0.7rem;
      border: 1px solid rgba(140, 154, 171, 0.45);
      border-radius: 8px;
      background: #fff;
      color: #15202b;
      font: inherit;
    }
    .atoll-table-toolbar .atoll-table-status {
      font-size: 0.875rem;
      color: #5c6673;
    }
    .atoll-table-pagination {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.5rem;
      margin: 0.65rem 0 0.2rem;
    }
    .atoll-table-pagination button {
      border: 1px solid rgba(140, 154, 171, 0.45);
      background: #fff;
      color: #15202b;
      border-radius: 8px;
      padding: 0.35rem 0.7rem;
      font: inherit;
      cursor: pointer;
    }
    .atoll-table-pagination button[disabled] {
      opacity: 0.45;
      cursor: not-allowed;
    }
    .atoll-table-page-label {
      font-size: 0.875rem;
      color: #5c6673;
    }
    .atoll-table-sortable {
      cursor: pointer;
      user-select: none;
      position: relative;
      padding-right: 1.2rem;
    }
    .atoll-table-sortable::after {
      content: '';
      position: absolute;
      right: 0.2rem;
      top: 50%;
      transform: translateY(-50%);
      border-left: 0.25rem solid transparent;
      border-right: 0.25rem solid transparent;
      border-top: 0.36rem solid rgba(21, 32, 43, 0.35);
      opacity: 0.35;
    }
    .atoll-table-sortable[data-sort-direction="asc"]::after {
      border-top: 0;
      border-bottom: 0.36rem solid rgba(21, 32, 43, 0.7);
      opacity: 1;
    }
    .atoll-table-sortable[data-sort-direction="desc"]::after {
      border-top: 0.36rem solid rgba(21, 32, 43, 0.7);
      opacity: 1;
    }
    .atoll-table-empty {
      text-align: center;
      color: #5c6673;
      padding: 0.9rem;
      font-style: italic;
    }
  `;
  document.head.appendChild(style);
};

const clampPageSize = (value) => {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed <= 0) return 10;
  return Math.min(200, Math.max(1, Math.floor(parsed)));
};

export default function mount(el, props = {}) {
  ensureStyle();

  const opts = {
    ...DEFAULTS,
    ...(props || {}),
    searchable: props?.searchable !== false,
    pageSize: clampPageSize(props?.pageSize ?? DEFAULTS.pageSize)
  };

  const scopeSelector = normalizeText(props?.scopeSelector || '');
  let scope = document;
  if (scopeSelector !== '') {
    scope = el.closest(scopeSelector) || document.querySelector(scopeSelector) || document;
  }

  let table = null;
  const selector = normalizeText(opts.selector);
  if (selector !== '') {
    table = scope.querySelector(selector);
  }
  if (!(table instanceof HTMLTableElement) && el.previousElementSibling instanceof HTMLTableElement) {
    table = el.previousElementSibling;
  }
  if (!(table instanceof HTMLTableElement)) {
    return;
  }
  if (table.dataset.tablesEnhanced === '1') {
    return;
  }
  table.dataset.tablesEnhanced = '1';

  const tbody = table.tBodies?.[0];
  if (!(tbody instanceof HTMLTableSectionElement)) {
    return;
  }

  const originalRows = Array.from(tbody.rows).map((row) => ({
    node: row.cloneNode(true),
    text: normalizeText(row.textContent),
    cells: Array.from(row.cells).map((cell) => normalizeText(cell.textContent))
  }));

  const toolbar = document.createElement('div');
  toolbar.className = 'atoll-table-toolbar';
  const status = document.createElement('span');
  status.className = 'atoll-table-status';
  toolbar.appendChild(status);

  let searchInput = null;
  if (opts.searchable) {
    searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.placeholder = normalizeText(opts.searchPlaceholder || DEFAULTS.searchPlaceholder);
    toolbar.insertBefore(searchInput, status);
  }

  const pagination = document.createElement('div');
  pagination.className = 'atoll-table-pagination';

  table.parentNode?.insertBefore(toolbar, table);
  if (table.nextSibling) {
    table.parentNode?.insertBefore(pagination, table.nextSibling);
  } else {
    table.parentNode?.appendChild(pagination);
  }

  const state = {
    query: '',
    sortIndex: -1,
    sortDirection: 'asc',
    page: 1
  };

  const headers = Array.from(table.tHead?.rows?.[0]?.cells || []);
  headers.forEach((headerCell, index) => {
    headerCell.classList.add('atoll-table-sortable');
    headerCell.setAttribute('role', 'button');
    headerCell.tabIndex = 0;
    headerCell.addEventListener('click', () => {
      if (state.sortIndex === index) {
        state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
      } else {
        state.sortIndex = index;
        state.sortDirection = 'asc';
      }
      state.page = 1;
      render();
    });
    headerCell.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      headerCell.click();
    });
  });

  const updateHeaderState = () => {
    headers.forEach((headerCell, index) => {
      if (index !== state.sortIndex) {
        headerCell.removeAttribute('data-sort-direction');
        return;
      }
      headerCell.setAttribute('data-sort-direction', state.sortDirection);
    });
  };

  const filteredSortedRows = () => {
    const q = state.query.toLowerCase();
    const rows = originalRows.filter((row) => (q === '' ? true : row.text.toLowerCase().includes(q)));
    if (state.sortIndex >= 0) {
      rows.sort((left, right) => {
        const cmp = compareValues(left.cells[state.sortIndex], right.cells[state.sortIndex]);
        return state.sortDirection === 'asc' ? cmp : -cmp;
      });
    }
    return rows;
  };

  const renderPagination = (current, totalPages) => {
    pagination.replaceChildren();
    if (totalPages <= 1) return;

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.textContent = 'Zurueck';
    prev.disabled = current <= 1;
    prev.addEventListener('click', () => {
      if (state.page <= 1) return;
      state.page -= 1;
      render();
    });

    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = 'Weiter';
    next.disabled = current >= totalPages;
    next.addEventListener('click', () => {
      if (state.page >= totalPages) return;
      state.page += 1;
      render();
    });

    const label = document.createElement('span');
    label.className = 'atoll-table-page-label';
    label.textContent = `Seite ${current} / ${totalPages}`;

    pagination.append(prev, label, next);
  };

  const render = () => {
    updateHeaderState();
    const rows = filteredSortedRows();
    const total = rows.length;
    const pageSize = opts.pageSize;
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    state.page = Math.max(1, Math.min(state.page, totalPages));

    const start = (state.page - 1) * pageSize;
    const pageRows = rows.slice(start, start + pageSize);
    tbody.replaceChildren();

    if (pageRows.length === 0) {
      const empty = document.createElement('tr');
      const cell = document.createElement('td');
      cell.className = 'atoll-table-empty';
      cell.colSpan = Math.max(1, headers.length || table.rows?.[0]?.cells?.length || 1);
      cell.textContent = normalizeText(opts.emptyMessage || DEFAULTS.emptyMessage);
      empty.appendChild(cell);
      tbody.appendChild(empty);
    } else {
      pageRows.forEach((entry) => tbody.appendChild(entry.node.cloneNode(true)));
    }

    const from = total === 0 ? 0 : start + 1;
    const to = Math.min(total, start + pageRows.length);
    status.textContent = `${from}-${to} von ${total} Zeilen`;
    renderPagination(state.page, totalPages);
  };

  if (searchInput) {
    searchInput.addEventListener('input', (event) => {
      state.query = normalizeText(event.currentTarget?.value || '');
      state.page = 1;
      render();
    });
  }

  render();
}
