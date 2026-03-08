'use strict';

var htmlRoot = document.getElementsByTagName('HTML')[0],
    savePanelStateEnabled = true,

    mobileOperator = function () {
        var userAgent = navigator.userAgent.toLowerCase();
        var isMobileUserAgent = /iphone|ipad|ipod|android|blackberry|mini|windows\sce|palm/i.test(userAgent);
        var isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        var isSmallScreen = window.innerWidth <= 992;
        return isMobileUserAgent || isTouchDevice || isSmallScreen;
    },

    filterClass = function (t, e) {
        return String(t).split(/[^\w-]+/).filter(function (t) {
            return e.test(t);
        }).join(' ');
    },

    // ── loadThemeStyle: inject a <link id="theme-style"> into <head> ──────────
    loadThemeStyle = function (themeStyle) {
        if (!themeStyle || !themeStyle.trim()) return;
        var existingThemeStyle = document.getElementById('theme-style');
        if (existingThemeStyle) {
            existingThemeStyle.href = themeStyle;
        } else {
            var linkElement = document.createElement('link');
            linkElement.id = 'theme-style';
            linkElement.rel = 'stylesheet';
            linkElement.media = 'screen';
            linkElement.href = themeStyle;
            linkElement.setAttribute('data-loaded-from-storage', 'true');
            document.head.appendChild(linkElement);
        }
    },

    // ── loadSettings: server DB > localStorage fallback ───────────────────────
    loadSettings = function () {
        var e = {};

        // 1. Try server-provided settings (injected by layout.twig as window.__serverUiSettings)
        try {
            if (window.__serverUiSettings && typeof window.__serverUiSettings === 'object') {
                e = window.__serverUiSettings;
                // Also sync to localStorage so smartApp reads consistent data
                localStorage.setItem('layoutSettings', JSON.stringify(e));
            } else {
                // 2. Fall back to localStorage
                var t = localStorage.getItem('layoutSettings') || '';
                e = t ? JSON.parse(t) : {};
            }
        } catch (ex) {}

        var savedTheme = e.theme || 'dark';
        htmlRoot.setAttribute('data-bs-theme', savedTheme);

        var themeStyle = e.themeStyle || '';
        if (themeStyle) {
            loadThemeStyle(themeStyle);
        }

        return Object.assign({ htmlRoot: '', theme: savedTheme, themeStyle: themeStyle }, e);
    },

    // ── saveSettings: localStorage + AJAX to server DB ────────────────────────
    saveSettings = function () {
        layoutSettings.htmlRoot   = filterClass(htmlRoot.className, /^(set)-/i);
        layoutSettings.theme      = htmlRoot.getAttribute('data-bs-theme') || 'dark';

        var themeStyleElement = document.getElementById('theme-style');
        layoutSettings.themeStyle = (themeStyleElement && themeStyleElement.getAttribute('href'))
            ? themeStyleElement.getAttribute('href')
            : '';

        var json = JSON.stringify(layoutSettings);

        // Always keep localStorage in sync
        localStorage.setItem('layoutSettings', json);

        // Persist to server DB via AJAX (fire-and-forget)
        try {
            fetch('/api/ui-settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: json,
                credentials: 'same-origin'
            });
        } catch (ex) {}

        savingIndicator();
    },

    // ── resetSettings ─────────────────────────────────────────────────────────
    resetSettings = function () {
        localStorage.removeItem('layoutSettings');

        // Clear on server too
        try {
            fetch('/api/ui-settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({}),
                credentials: 'same-origin'
            });
        } catch (ex) {}

        htmlRoot.setAttribute('data-bs-theme', 'dark');
        var themeStyleElement = document.getElementById('theme-style');
        if (themeStyleElement) { themeStyleElement.setAttribute('href', ''); }
        window.location.reload();
    },

    getPageIdentifier = function () {
        return window.location.pathname.split('/').pop() || 'index';
    },

    savePanelState = function () {
        if (!savePanelStateEnabled) return;
        var state = [];
        var columns = document.querySelectorAll('.main-content > .row > [class^="col-"]');
        columns.forEach(function (column, columnIndex) {
            column.querySelectorAll('.panel').forEach(function (panel, position) {
                var panelHeader = panel.querySelector('.panel-hdr');
                var panelClasses = panel.className.split(' ').filter(function (cls) {
                    return cls !== 'panel' && cls !== 'panel-fullscreen';
                }).join(' ');
                var headerClasses = panelHeader ? panelHeader.className.split(' ').filter(function (cls) {
                    return cls !== 'panel-hdr';
                }).join(' ') : '';
                state.push({ id: panel.id, column: columnIndex, position: position, classes: panelClasses, headerClasses: headerClasses });
            });
        });
        var pageId = getPageIdentifier();
        var allStates = JSON.parse(localStorage.getItem('allPanelStates') || '{}');
        allStates[pageId] = state;
        localStorage.setItem('allPanelStates', JSON.stringify(allStates));
        savingIndicator();
    },

    loadPanelState = function () {
        var pageId = getPageIdentifier();
        var allStates = JSON.parse(localStorage.getItem('allPanelStates') || '{}');
        var savedState = allStates[pageId];
        if (!savedState) return;
        var columns = Array.from(document.querySelectorAll('.main-content > .row > [class^="col-"]'));
        var panelMap = {};
        columns.forEach(function (column) {
            Array.from(column.querySelectorAll('.panel')).forEach(function (panel) {
                panelMap[panel.id] = panel;
                panel.remove();
            });
        });
        savedState.sort(function (a, b) {
            return a.column !== b.column ? a.column - b.column : a.position - b.position;
        });
        savedState.forEach(function (item) {
            var panel = panelMap[item.id];
            if (panel && columns[item.column]) {
                panel.className = 'panel ' + (item.classes || '');
                var panelHeader = panel.querySelector('.panel-hdr');
                if (panelHeader && item.headerClasses) {
                    panelHeader.className = 'panel-hdr ' + item.headerClasses;
                }
                columns[item.column].appendChild(panel);
            }
        });
    },

    resetPanelState = function () {
        var pageId = getPageIdentifier();
        var allStates = JSON.parse(localStorage.getItem('allPanelStates') || '{}');
        delete allStates[pageId];
        localStorage.setItem('allPanelStates', JSON.stringify(allStates));
        window.location.reload();
    },

    savingIndicator = function () {
        var indicator = document.getElementById('saving-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'saving-indicator';
            document.body.appendChild(indicator);
        }
        indicator.className = 'saving-indicator spinner-border show';
        setTimeout(function () {
            indicator.className = 'saving-indicator spinner-border show success';
            setTimeout(function () {
                indicator.className = 'saving-indicator spinner-border success';
            }, 500);
        }, 300);
    },

    // ── Boot ──────────────────────────────────────────────────────────────────
    layoutSettings = loadSettings();

layoutSettings.htmlRoot && (htmlRoot.className = layoutSettings.htmlRoot);

// Expose on window so smartApp.js can find them
window.saveSettings   = saveSettings;
window.loadSettings   = loadSettings;
window.resetSettings  = resetSettings;
window.loadThemeStyle = loadThemeStyle;

loadPanelState();