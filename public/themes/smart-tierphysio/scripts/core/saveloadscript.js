'use strict';


var htmlRoot = document.getElementsByTagName('HTML')[0],
    //save states
    savePanelStateEnabled = true,

    //mobile operator on
    mobileOperator = function () {
        // Check user agent
        const userAgent = navigator.userAgent.toLowerCase();
        const isMobileUserAgent = /iphone|ipad|ipod|android|blackberry|mini|windows\sce|palm/i.test(userAgent);

        // Check for touch support
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

        // Check screen size (optional)
        const isSmallScreen = window.innerWidth <= 992; // Adjust the breakpoint as needed

        // Return true if any of the conditions are met
        return isMobileUserAgent || isTouchDevice || isSmallScreen;
    },

    //filter
    filterClass = function (t, e) {
        return String(t).split(/[^\w-]+/).filter(function (t) {
            return e.test(t)
        }).join(' ')
    },

    //load
    loadSettings = function () {
        var t = localStorage.getItem('layoutSettings') || '',
            e = t ? JSON.parse(t) : {};

        // Load theme setting
        var savedTheme = e.theme || 'dark';
        htmlRoot.setAttribute('data-bs-theme', savedTheme);

        // Load theme style CSS file only if one was saved
        var themeStyle = e.themeStyle || '';
        if (themeStyle) {
            loadThemeStyle(themeStyle);
        }

        return Object.assign({
            htmlRoot: '',
            theme: savedTheme,
            themeStyle: themeStyle
        }, e)
    },

    //save
    saveSettings = function () {
        // Save root HTML classes
        layoutSettings.htmlRoot = filterClass(htmlRoot.className, /^(set)-/i);

        // Save theme attribute
        layoutSettings.theme = htmlRoot.getAttribute('data-bs-theme') || 'dark';

        // Save theme style CSS path
        var themeStyleElement = document.getElementById('theme-style');
        if (themeStyleElement && themeStyleElement.getAttribute('href')) {
            // Get complete href attribute
            layoutSettings.themeStyle = themeStyleElement.getAttribute('href');
            console.log('Saved theme style:', layoutSettings.themeStyle);
        } else {
            layoutSettings.themeStyle = '';
            console.log('No theme style to save');
        }

        // Log the full settings object before saving
        console.log('Saving layout settings:', JSON.stringify(layoutSettings));

        // Save to localStorage
        localStorage.setItem("layoutSettings", JSON.stringify(layoutSettings));

        // Show saving indicator
        savingIndicator();
    },

    // reset
    resetSettings = function () {
        localStorage.setItem("layoutSettings", "");
        // reset data-bs-theme
        htmlRoot.setAttribute('data-bs-theme', 'light');

        // reset theme style element if it exists
        const themeStyleElement = document.getElementById('theme-style')
        if (themeStyleElement) {
            themeStyleElement.setAttribute('href', '');
        }

        // refresh page
        window.location.reload();


    },

    //load theme style
    loadThemeStyle = function (themeStyle) {
        if (!themeStyle) return;

        // Don't do anything if the URL is empty
        if (!themeStyle.trim()) return;

        // Get existing theme style if it exists
        var existingThemeStyle = document.getElementById('theme-style');

        if (existingThemeStyle) {
            // Update existing theme style's href
            existingThemeStyle.href = themeStyle;
        } else {
            // Create new theme style element if none exists
            var linkElement = document.createElement('link');
            linkElement.id = 'theme-style'; // Use the standard ID
            linkElement.rel = 'stylesheet';
            linkElement.media = 'screen';
            linkElement.href = themeStyle;
            document.head.appendChild(linkElement);

            // Flag to indicate this was loaded from localStorage
            linkElement.setAttribute('data-loaded-from-storage', 'true');
        }
    },

    //get page id
    getPageIdentifier = function () {
        return window.location.pathname.split('/').pop() || 'index.html';
    },

    //save panel state
    savePanelState = function () {
        if (!savePanelStateEnabled) return;

        var state = [];
        var columns = document.querySelectorAll('.main-content > .row > [class^="col-"]');
        columns.forEach(function (column, columnIndex) {
            var panels = column.querySelectorAll('.panel');
            panels.forEach(function (panel, position) {
                var panelHeader = panel.querySelector('.panel-hdr');

                // Save panel classes excluding 'panel' and 'panel-fullscreen'
                var panelClasses = panel.className.split(' ').filter(function (cls) {
                    return cls !== 'panel' && cls !== 'panel-fullscreen';
                }).join(' ');

                // Save header classes excluding 'panel-hdr'
                var headerClasses = panelHeader ? panelHeader.className.split(' ').filter(function (cls) {
                    return cls !== 'panel-hdr';
                }).join(' ') : '';

                state.push({
                    id: panel.id,
                    column: columnIndex,
                    position: position, // Save position within column
                    classes: panelClasses,
                    headerClasses: headerClasses
                });
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

        // Use same selector as save function
        var columns = Array.from(document.querySelectorAll('.main-content > .row > [class^="col-"]'));

        // Store all existing panels in a map before removing them
        var panelMap = {};
        columns.forEach(function (column) {
            var existingPanels = Array.from(column.querySelectorAll('.panel'));
            existingPanels.forEach(function (panel) {
                panelMap[panel.id] = panel;
                panel.remove();
            });
        });

        // Sort state by column and position
        savedState.sort(function (a, b) {
            if (a.column === b.column) {
                return a.position - b.position;
            }
            return a.column - b.column;
        });

        // Reinsert panels in correct order
        savedState.forEach(function (item) {
            var panel = panelMap[item.id];
            if (panel && columns[item.column]) {
                // Update panel classes
                panel.className = 'panel ' + (item.classes || '');

                // Update header classes
                var panelHeader = panel.querySelector('.panel-hdr');
                if (panelHeader && item.headerClasses) {
                    panelHeader.className = 'panel-hdr ' + item.headerClasses;
                }

                // Append to correct column
                columns[item.column].appendChild(panel);
            }
        });
    },

    // Reset panel state
    resetPanelState = function () {
        var pageId = getPageIdentifier();
        var allStates = JSON.parse(localStorage.getItem('allPanelStates') || '{}');
        delete allStates[pageId];
        localStorage.setItem('allPanelStates', JSON.stringify(allStates));
        //refresh page
        window.location.reload();
    },

    savingIndicator = function () {
        // Create or get the indicator element
        let indicator = document.getElementById('saving-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'saving-indicator';
            document.body.appendChild(indicator);
        }

        // Show saving animation
        //indicator.textContent = '';
        indicator.className = 'saving-indicator spinner-border show';

        // After a brief delay, show success and hide
        setTimeout(() => {
            //indicator.textContent = '';
            indicator.className = 'saving-indicator spinner-border show success';
            setTimeout(() => {
                indicator.className = 'saving-indicator spinner-border success';
            }, 500);
        }, 300);
    },

    //load page layout settings
    layoutSettings = loadSettings();
layoutSettings.htmlRoot && (htmlRoot.className = layoutSettings.htmlRoot);

// Load panel settings is triggered just before <script> tag


loadPanelState();