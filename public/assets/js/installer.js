/* ============================================================
   Tierphysio Manager 3.0 — Installer JavaScript
   ============================================================ */

'use strict';

/* ── Password toggle (show/hide) ── */
function initPasswordToggles() {
    document.querySelectorAll('.inst-pw-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (!input) return;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.innerHTML = isHidden
                ? '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
                : '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>';
        });
    });
}

/* ── Password strength meter ── */
function initPasswordStrength() {
    const pwInput   = document.getElementById('admin_password');
    const bar       = document.getElementById('pw-strength-bar');
    const fill      = document.getElementById('pw-strength-fill');
    const label     = document.getElementById('pw-strength-label');
    if (!pwInput || !bar || !fill || !label) return;

    pwInput.addEventListener('input', () => {
        const pw = pwInput.value;
        if (!pw) {
            bar.classList.remove('visible');
            fill.style.width = '0%';
            label.textContent = '';
            return;
        }

        bar.classList.add('visible');
        const score = calcStrength(pw);

        const levels = [
            { max: 25,  color: '#ef4444', text: 'Sehr schwach',  textColor: '#f87171' },
            { max: 50,  color: '#f59e0b', text: 'Schwach',        textColor: '#fbbf24' },
            { max: 75,  color: '#eab308', text: 'Mittel',         textColor: '#facc15' },
            { max: 90,  color: '#22c55e', text: 'Stark',          textColor: '#4ade80' },
            { max: 101, color: '#10b981', text: 'Sehr stark',     textColor: '#34d399' },
        ];
        const level = levels.find(l => score <= l.max) || levels[levels.length - 1];

        fill.style.width     = score + '%';
        fill.style.background = level.color;
        label.textContent    = level.text;
        label.style.color    = level.textColor;
    });
}

function calcStrength(pw) {
    let score = 0;
    if (pw.length >= 8)  score += 20;
    if (pw.length >= 12) score += 10;
    if (pw.length >= 16) score += 10;
    if (/[a-z]/.test(pw)) score += 10;
    if (/[A-Z]/.test(pw)) score += 15;
    if (/[0-9]/.test(pw)) score += 15;
    if (/[^a-zA-Z0-9]/.test(pw)) score += 20;
    return Math.min(score, 100);
}

/* ── Password match indicator ── */
function initPasswordMatch() {
    const pw1   = document.getElementById('admin_password');
    const pw2   = document.getElementById('admin_password_confirm');
    const label = document.getElementById('pw-match-label');
    if (!pw1 || !pw2 || !label) return;

    const check = () => {
        if (!pw2.value) { label.textContent = ''; return; }
        if (pw1.value === pw2.value) {
            label.textContent = '✓ Passwörter stimmen überein';
            label.style.color = '#4ade80';
        } else {
            label.textContent = '✗ Passwörter stimmen nicht überein';
            label.style.color = '#f87171';
        }
    };

    pw1.addEventListener('input', check);
    pw2.addEventListener('input', check);
}

/* ── DB connection test ── */
function initDbTest() {
    const btn    = document.getElementById('test-db-btn');
    const result = document.getElementById('db-test-result');
    const icon   = document.getElementById('test-db-icon');
    const lbl    = document.getElementById('test-db-label');
    if (!btn || !result) return;

    btn.addEventListener('click', async () => {
        const form = document.getElementById('install-form');
        if (!form) return;

        btn.disabled = true;
        if (icon) icon.innerHTML = '';
        if (lbl)  lbl.textContent = 'Teste...';

        // Spinner inside button
        const spinner = document.createElement('span');
        spinner.className = 'inst-spinner';
        if (icon) icon.replaceWith(spinner);

        result.textContent = '';
        result.className   = 'inst-db-result';

        try {
            const data = new FormData(form);
            const res  = await fetch('/install/check-db', { method: 'POST', body: data });
            const json = await res.json();

            result.textContent = json.success ? '✓ ' + json.message : '✗ ' + json.message;
            result.classList.add(json.success ? 'ok' : 'fail');
        } catch {
            result.textContent = '✗ Verbindungstest fehlgeschlagen.';
            result.classList.add('fail');
        } finally {
            btn.disabled = false;
            const newIcon = document.createElement('svg');
            newIcon.setAttribute('width', '14');
            newIcon.setAttribute('height', '14');
            newIcon.setAttribute('fill', 'none');
            newIcon.setAttribute('viewBox', '0 0 24 24');
            newIcon.id = 'test-db-icon';
            newIcon.innerHTML = '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M22 12h-4l-3 9L9 3l-3 9H2"/>';
            spinner.replaceWith(newIcon);
            if (lbl) lbl.textContent = 'Verbindung testen';
        }
    });
}

/* ── Submit button loading state ── */
function initSubmitLoading() {
    document.querySelectorAll('#db-submit-btn, #admin-submit-btn').forEach(btn => {
        const form = btn.closest('form');
        if (!form) return;
        form.addEventListener('submit', () => {
            btn.disabled = true;
            const spinner = document.createElement('span');
            spinner.className = 'inst-spinner';
            btn.innerHTML = '';
            btn.appendChild(spinner);
            const txt = document.createElement('span');
            txt.textContent = 'Bitte warten...';
            btn.appendChild(txt);
        });
    });
}

/* ── Admin form: client-side guard ── */
function initAdminFormGuard() {
    const form = document.getElementById('admin-form');
    if (!form) return;

    form.addEventListener('submit', (e) => {
        const pw1 = document.getElementById('admin_password')?.value  || '';
        const pw2 = document.getElementById('admin_password_confirm')?.value || '';
        const email = document.getElementById('admin_email')?.value || '';

        if (pw1.length < 8) {
            e.preventDefault();
            showInlineError('admin_password', 'Passwort muss mindestens 8 Zeichen lang sein.');
            return;
        }
        if (pw1 !== pw2) {
            e.preventDefault();
            showInlineError('admin_password_confirm', 'Passwörter stimmen nicht überein.');
            return;
        }
        if (!email.includes('@')) {
            e.preventDefault();
            showInlineError('admin_email', 'Bitte eine gültige E-Mail-Adresse eingeben.');
            return;
        }
    });
}

function showInlineError(inputId, message) {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.style.borderColor = '#ef4444';
    input.style.boxShadow   = '0 0 0 3px rgba(239,68,68,0.15)';
    input.focus();

    const existing = input.parentElement.querySelector('.inst-inline-error');
    if (existing) existing.remove();

    const msg = document.createElement('div');
    msg.className   = 'inst-inline-error';
    msg.style.cssText = 'color:#f87171;font-size:0.75rem;font-weight:600;margin-top:0.35rem;';
    msg.textContent = message;
    input.parentElement.appendChild(msg);

    input.addEventListener('input', () => {
        input.style.borderColor = '';
        input.style.boxShadow   = '';
        msg.remove();
    }, { once: true });
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
    initPasswordToggles();
    initPasswordStrength();
    initPasswordMatch();
    initDbTest();
    initSubmitLoading();
    initAdminFormGuard();
});
