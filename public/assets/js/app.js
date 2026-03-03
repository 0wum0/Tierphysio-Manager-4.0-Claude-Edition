/* ============================================================
   Tierphysio Manager 3.0 - App JavaScript
   ============================================================ */

'use strict';

// ============================================================
// Theme Manager
// ============================================================
const ThemeManager = {
    init() {
        const saved = localStorage.getItem('theme') || document.documentElement.getAttribute('data-theme') || 'dark';
        this.apply(saved);
    },
    apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        const btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.title = theme === 'dark' ? 'Helles Design' : 'Dunkles Design';
            btn.innerHTML = theme === 'dark'
                ? '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364-.707.707M6.343 17.657l-.707.707m12.728 0-.707-.707M6.343 6.343l-.707-.707M12 7a5 5 0 1 0 0 10A5 5 0 0 0 12 7Z"/></svg>'
                : '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>';
        }
    },
    toggle() {
        const current = document.documentElement.getAttribute('data-theme') || 'dark';
        this.apply(current === 'dark' ? 'light' : 'dark');
    }
};

// ============================================================
// Sidebar / Mobile
// ============================================================
const Sidebar = {
    init() {
        const toggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        if (toggle && sidebar) {
            toggle.addEventListener('click', () => this.open());
        }
        if (overlay) {
            overlay.addEventListener('click', () => this.close());
        }
    },
    open() {
        document.getElementById('sidebar')?.classList.add('open');
        document.getElementById('sidebar-overlay')?.classList.add('open');
    },
    close() {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('sidebar-overlay')?.classList.remove('open');
    }
};

// ============================================================
// Modal Manager
// ============================================================
const Modal = {
    open(id) {
        const overlay = document.getElementById(id);
        if (!overlay) return;
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) Modal.close(id);
        }, { once: true });
    },
    close(id) {
        const overlay = document.getElementById(id);
        if (!overlay) return;
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    },
    closeAll() {
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
            m.classList.remove('open');
        });
        document.body.style.overflow = '';
    }
};

// ============================================================
// Dropdown Manager
// ============================================================
const Dropdown = {
    init() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-dropdown]');
            if (trigger) {
                e.stopPropagation();
                const targetId = trigger.getAttribute('data-dropdown');
                const menu = document.getElementById(targetId);
                if (menu) {
                    const isOpen = menu.classList.contains('open');
                    this.closeAll();
                    if (!isOpen) menu.classList.add('open');
                }
                return;
            }
            if (!e.target.closest('.dropdown-menu')) {
                this.closeAll();
            }
        });
    },
    closeAll() {
        document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    }
};

// ============================================================
// Flash Message Auto-dismiss
// ============================================================
const FlashMessages = {
    init() {
        document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
            const delay = parseInt(alert.dataset.autoDismiss) || 5000;
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-8px)';
                alert.style.transition = 'opacity 0.3s, transform 0.3s';
                setTimeout(() => alert.remove(), 300);
            }, delay);
        });
    }
};

// ============================================================
// Confirm Delete
// ============================================================
const ConfirmDelete = {
    init() {
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', (e) => {
                const msg = el.getAttribute('data-confirm') || 'Wirklich löschen?';
                if (!confirm(msg)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });
    }
};

// ============================================================
// Invoice Position Manager
// ============================================================
const InvoicePositions = {
    init() {
        const container = document.getElementById('positions-container');
        if (!container) return;

        document.getElementById('add-position')?.addEventListener('click', () => this.addRow());
        container.addEventListener('click', (e) => {
            if (e.target.closest('.remove-position')) {
                const row = e.target.closest('.position-row');
                if (container.querySelectorAll('.position-row').length > 1) {
                    row?.remove();
                    this.updateTotals();
                }
            }
        });
        container.addEventListener('input', (e) => {
            if (e.target.matches('.pos-qty, .pos-price, .pos-tax')) {
                this.updateRowTotal(e.target.closest('.position-row'));
                this.updateTotals();
            }
        });
        this.updateTotals();
    },

    addRow() {
        const container = document.getElementById('positions-container');
        const template  = document.getElementById('position-row-template');
        if (!container || !template) return;
        const clone = template.content.cloneNode(true);
        container.appendChild(clone);
    },

    updateRowTotal(row) {
        if (!row) return;
        const qty   = parseFloat(row.querySelector('.pos-qty')?.value.replace(',','.')) || 0;
        const price = parseFloat(row.querySelector('.pos-price')?.value.replace(',','.')) || 0;
        const total = qty * price;
        const el = row.querySelector('.pos-total');
        if (el) el.textContent = formatMoney(total);
    },

    updateTotals() {
        let net = 0, tax = 0;
        document.querySelectorAll('.position-row').forEach(row => {
            const qty    = parseFloat(row.querySelector('.pos-qty')?.value.replace(',','.')) || 0;
            const price  = parseFloat(row.querySelector('.pos-price')?.value.replace(',','.')) || 0;
            const taxPct = parseFloat(row.querySelector('.pos-tax')?.value.replace(',','.')) || 0;
            const lineNet = qty * price;
            net += lineNet;
            tax += lineNet * (taxPct / 100);
        });
        const gross = net + tax;
        const el = (id) => document.getElementById(id);
        if (el('total-net'))   el('total-net').textContent   = formatMoney(net);
        if (el('total-tax'))   el('total-tax').textContent   = formatMoney(tax);
        if (el('total-gross')) el('total-gross').textContent = formatMoney(gross);
    }
};

// ============================================================
// Dashboard Chart
// ============================================================
const DashboardChart = {
    chart: null,
    async init() {
        const canvas = document.getElementById('revenue-chart');
        if (!canvas) return;

        const type = document.getElementById('chart-type')?.value || 'weekly';
        await this.load(type);

        document.querySelectorAll('[data-chart-type]').forEach(btn => {
            btn.addEventListener('click', async () => {
                document.querySelectorAll('[data-chart-type]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                await this.load(btn.getAttribute('data-chart-type'));
            });
        });
    },

    async load(type) {
        try {
            const res  = await fetch(`/dashboard/chart-data?type=${type}`);
            const data = await res.json();
            this.render(data, type);
        } catch (e) {
            console.error('Chart load error:', e);
        }
    },

    render(data, type) {
        const canvas = document.getElementById('revenue-chart');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        if (this.chart) { this.chart.destroy(); }

        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
        const textColor = isDark ? '#9090b0' : '#6060a0';

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Umsatz',
                    data: data.data,
                    borderColor: '#4f7cff',
                    backgroundColor: 'rgba(79,124,255,0.1)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4f7cff',
                    pointBorderColor: isDark ? '#0a0a14' : '#f0f2f8',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#141428' : '#fff',
                        borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
                        borderWidth: 1,
                        titleColor: isDark ? '#f0f0ff' : '#0f0f1e',
                        bodyColor: textColor,
                        padding: 12,
                        callbacks: {
                            label: (ctx) => ' ' + formatMoney(ctx.parsed.y)
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { size: 11 } }
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: {
                            color: textColor,
                            font: { size: 11 },
                            callback: (v) => formatMoney(v)
                        }
                    }
                }
            }
        });
    }
};

// ============================================================
// DB test in installer
// ============================================================
const InstallerDB = {
    init() {
        const btn = document.getElementById('test-db-btn');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            const result = document.getElementById('db-test-result');
            btn.disabled = true;
            btn.textContent = 'Teste...';
            const form = document.getElementById('install-form');
            if (!form) return;
            const data = new FormData(form);
            try {
                const res  = await fetch('/install/check-db', { method: 'POST', body: data });
                const json = await res.json();
                result.textContent  = json.message;
                result.className    = 'mt-2 text-sm ' + (json.success ? 'text-success' : 'text-danger');
            } catch {
                result.textContent = 'Verbindungstest fehlgeschlagen.';
                result.className   = 'mt-2 text-sm text-danger';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Verbindung testen';
            }
        });
    }
};

// ============================================================
// Helpers
// ============================================================
function formatMoney(amount) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount);
}

// ============================================================
// Custom Select
// ============================================================
const CustomSelect = {
    init(root) {
        const scope = root || document;
        scope.querySelectorAll('select.form-control:not([data-cs-init])').forEach(sel => {
            this.build(sel);
        });
    },

    build(sel) {
        if (sel.dataset.csInit) return;
        sel.dataset.csInit = '1';
        sel.style.display = 'none';

        const wrapper = document.createElement('div');
        wrapper.className = 'cs-wrapper';
        wrapper.style.cssText = 'position:relative;width:100%;display:inline-block;';
        if (sel.disabled) wrapper.classList.add('cs-disabled');

        const trigger = document.createElement('div');
        trigger.className = 'cs-trigger form-control';
        trigger.setAttribute('tabindex', sel.disabled ? '-1' : '0');
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-expanded', 'false');

        const triggerText = document.createElement('span');
        triggerText.className = 'cs-trigger-text';

        const arrow = document.createElement('span');
        arrow.className = 'cs-arrow';
        arrow.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>';

        trigger.appendChild(triggerText);
        trigger.appendChild(arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'cs-dropdown';
        dropdown.setAttribute('role', 'listbox');
        dropdown.style.cssText = 'display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:9999;border-radius:12px;overflow-y:auto;max-height:240px;';

        wrapper.appendChild(trigger);
        wrapper.appendChild(dropdown);
        sel.parentNode.insertBefore(wrapper, sel.nextSibling);

        const getVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

        const applyDropdownStyle = () => {
            const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
            const bg     = isDark ? '#141428' : '#ffffff';
            const border = isDark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.12)';
            const shadow = isDark ? '0 8px 32px rgba(0,0,0,0.5)' : '0 8px 32px rgba(0,0,0,0.12)';
            dropdown.style.background  = bg;
            dropdown.style.border      = `1px solid ${border}`;
            dropdown.style.boxShadow   = shadow;
        };

        const syncOptions = () => {
            dropdown.innerHTML = '';
            applyDropdownStyle();
            const isDark   = document.documentElement.getAttribute('data-theme') !== 'light';
            const bgNormal  = isDark ? '#141428' : '#ffffff';
            const bgHover   = isDark ? 'rgba(79,124,255,0.15)' : '#f0f2f8';
            const bgSelected = isDark ? 'rgba(79,124,255,0.25)' : 'rgba(59,110,240,0.12)';
            const colNormal  = isDark ? '#f0f0ff' : '#0f0f1e';
            const colAccent  = isDark ? '#6b9dff' : '#3b6ef0';

            Array.from(sel.options).forEach((opt, i) => {
                const item = document.createElement('div');
                item.className = 'cs-option';
                item.textContent = opt.textContent;
                item.dataset.value = opt.value;
                item.dataset.index = i;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', opt.selected ? 'true' : 'false');

                const isSelected = opt.selected;
                const isDisabled = opt.disabled;

                item.style.cssText = `
                    padding:0.55rem 0.875rem;
                    font-size:0.875rem;
                    cursor:${isDisabled ? 'not-allowed' : 'pointer'};
                    opacity:${isDisabled ? '0.4' : '1'};
                    background:${isSelected ? bgSelected : bgNormal};
                    color:${isSelected ? colAccent : colNormal};
                    font-weight:${isSelected ? '600' : '400'};
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
                    transition:background 0.12s,color 0.12s;
                `;

                if (!isDisabled) {
                    item.addEventListener('mouseenter', () => {
                        item.style.background = bgHover;
                        item.style.color = colAccent;
                    });
                    item.addEventListener('mouseleave', () => {
                        const sel2 = parseInt(item.dataset.index) === sel.selectedIndex;
                        item.style.background = sel2 ? bgSelected : bgNormal;
                        item.style.color = sel2 ? colAccent : colNormal;
                    });
                    item.addEventListener('click', e => {
                        e.stopPropagation();
                        sel.selectedIndex = i;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        syncSelected();
                        close();
                    });
                }
                dropdown.appendChild(item);
            });
        };

        const syncSelected = () => {
            const opt = sel.options[sel.selectedIndex];
            triggerText.textContent = opt ? opt.textContent : '';
            dropdown.querySelectorAll('.cs-option').forEach((el, i) => {
                el.classList.toggle('cs-selected', i === sel.selectedIndex);
                el.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false');
            });
        };

        const open = () => {
            if (sel.disabled) return;
            document.querySelectorAll('.cs-wrapper.cs-open').forEach(w => {
                if (w !== wrapper) w.classList.remove('cs-open');
            });
            /* Re-render options with current theme colors */
            syncOptions();
            wrapper.classList.add('cs-open');
            dropdown.style.display = 'block';
            trigger.setAttribute('aria-expanded', 'true');
            const rect = wrapper.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            dropdown.style.maxHeight = Math.min(240, Math.max(spaceBelow - 8, 120)) + 'px';
        };

        const close = () => {
            wrapper.classList.remove('cs-open');
            dropdown.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
        };

        trigger.addEventListener('click', e => {
            e.stopPropagation();
            wrapper.classList.contains('cs-open') ? close() : open();
        });

        trigger.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); wrapper.classList.contains('cs-open') ? close() : open(); }
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowDown') { e.preventDefault(); sel.selectedIndex = Math.min(sel.selectedIndex + 1, sel.options.length - 1); sel.dispatchEvent(new Event('change', { bubbles: true })); syncSelected(); }
            if (e.key === 'ArrowUp')   { e.preventDefault(); sel.selectedIndex = Math.max(sel.selectedIndex - 1, 0); sel.dispatchEvent(new Event('change', { bubbles: true })); syncSelected(); }
        });

        /* Rebuild when options change (e.g. AJAX populated selects) */
        const mo = new MutationObserver(() => { syncOptions(); syncSelected(); });
        mo.observe(sel, { childList: true });

        syncOptions();
        syncSelected();
    },

    /* Re-init after dynamic content is added */
    refresh(root) {
        this.init(root);
    }
};

// ============================================================
// Init on DOM ready
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    ThemeManager.init();
    Sidebar.init();
    Dropdown.init();
    FlashMessages.init();
    ConfirmDelete.init();
    InvoicePositions.init();
    DashboardChart.init();
    InstallerDB.init();
    CustomSelect.init();

    /* Close all custom selects on outside click */
    document.addEventListener('click', () => {
        document.querySelectorAll('.cs-wrapper.cs-open').forEach(w => w.classList.remove('cs-open'));
    });

    document.getElementById('theme-toggle')?.addEventListener('click', () => ThemeManager.toggle());

    document.querySelectorAll('[data-modal-open]').forEach(btn => {
        btn.addEventListener('click', () => Modal.open(btn.getAttribute('data-modal-open')));
    });
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => Modal.close(btn.getAttribute('data-modal-close')));
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') Modal.closeAll();
    });
});
