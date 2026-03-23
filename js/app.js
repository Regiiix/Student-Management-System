/**
 * Student Management System - Frontend Optimizations
 * Includes: Debouncing, Form Validation, Lazy Loading, Performance Utils
 */

// ===================================
// Utility Functions
// ===================================

/**
 * Debounce function - delays execution until user stops typing
 * @param {Function} func Function to debounce
 * @param {number} wait Wait time in milliseconds
 * @returns {Function} Debounced function
 */
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Throttle function - limits execution to once per interval
 * @param {Function} func Function to throttle
 * @param {number} limit Time limit in milliseconds
 * @returns {Function} Throttled function
 */
function throttle(func, limit = 100) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Resolve an error-notice target to a DOM element.
 * @param {HTMLElement|string} target
 * @returns {HTMLElement|null}
 */
function resolveApiErrorTarget(target) {
    if (!target) return null;
    if (target instanceof HTMLElement) return target;
    if (typeof target === 'string') {
        if (target.startsWith('#')) {
            return document.querySelector(target);
        }
        return document.getElementById(target);
    }
    return null;
}

/**
 * Clear an API error notice element.
 * @param {HTMLElement|string} target
 */
function clearApiErrorNotice(target) {
    const notice = resolveApiErrorTarget(target);
    if (!notice) return;

    notice.textContent = '';
    notice.style.display = 'none';
    notice.style.alignItems = '';
    notice.style.justifyContent = '';
    notice.style.gap = '';
    notice.classList.add('hidden');
}

/**
 * Ensure all pages use a consistent site favicon.
 * Falls back for pages that do not explicitly declare <link rel="icon">.
 */
function ensureSiteFavicon() {
    const pagePath = (window.location.pathname || '').replace(/\\/g, '/');
    const assetPrefix = /\/(pages|api)\//.test(pagePath) ? '../' : '';
    const faviconHref = `${assetPrefix}images/site-favicon.svg`;

    const iconLinks = document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]');

    if (!iconLinks.length) {
        const icon = document.createElement('link');
        icon.setAttribute('rel', 'icon');
        icon.setAttribute('type', 'image/svg+xml');
        icon.setAttribute('href', faviconHref);
        document.head.appendChild(icon);
        return;
    }

    iconLinks.forEach((link) => {
        link.setAttribute('href', faviconHref);
        link.setAttribute('type', 'image/svg+xml');
    });
}

/**
 * Show an API error notice with optional retry action.
 * @param {HTMLElement|string} target
 * @param {string} message
 * @param {Function|null} onRetry
 * @param {Object} options
 */
function showApiErrorNotice(target, message, onRetry = null, options = {}) {
    const notice = resolveApiErrorTarget(target);
    if (!notice) return;

    const fallbackMessage = options.fallbackMessage || 'Unable to load data right now. Please try again.';
    const retryLabel = options.retryLabel || 'Retry';
    const buttonClass = options.buttonClass || 'btn btn-primary';

    notice.textContent = '';

    const text = document.createElement('span');
    text.textContent = message || fallbackMessage;
    notice.appendChild(text);

    if (typeof onRetry === 'function') {
        const retryBtn = document.createElement('button');
        retryBtn.type = 'button';
        retryBtn.className = buttonClass;
        retryBtn.textContent = retryLabel;
        retryBtn.style.minHeight = '34px';
        retryBtn.style.padding = '6px 12px';
        retryBtn.addEventListener('click', onRetry);
        notice.appendChild(retryBtn);

        notice.style.display = 'flex';
        notice.style.alignItems = 'center';
        notice.style.justifyContent = 'space-between';
        notice.style.gap = '12px';
    } else {
        notice.style.display = 'block';
    }

    notice.classList.remove('hidden');
}

// ===================================
// Form Validation
// ===================================

const Validator = {
    /**
     * Validate email format
     * @param {string} email Email to validate
     * @returns {boolean} True if valid
     */
    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    },

    /**
     * Validate phone number (Philippine format)
     * @param {string} phone Phone number to validate
     * @returns {boolean} True if valid
     */
    isValidPhone(phone) {
        if (!phone) return true; // Optional field
        // Accept legacy stored 10-digit local numbers (9xxxxxxxxx) in addition to 09xxxxxxxxx and +639xxxxxxxxx.
        const phoneRegex = /^(09|9|\+639)\d{9}$/;
        return phoneRegex.test(phone.replace(/[\s-]/g, ''));
    },

    /**
     * Validate required field
     * @param {string} value Value to check
     * @returns {boolean} True if not empty
     */
    isRequired(value) {
        return value !== null && value !== undefined && value.trim() !== '';
    },

    /**
     * Validate date (not in future, reasonable age)
     * @param {string} dateStr Date string to validate
     * @returns {boolean} True if valid
     */
    isValidBirthDate(dateStr) {
        if (!dateStr) return false;
        const date = new Date(dateStr);
        const today = new Date();
        const minDate = new Date();
        minDate.setFullYear(minDate.getFullYear() - 100); // Max 100 years old
        const maxDate = new Date();
        maxDate.setFullYear(maxDate.getFullYear() - 15); // Min 15 years old
        
        return date <= maxDate && date >= minDate;
    },

    /**
     * Show validation error on field
     * @param {HTMLElement} field Input field
     * @param {string} message Error message
     */
    showError(field, message) {
        // Remove existing error
        this.clearError(field);
        
        field.classList.add('input-error');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'validation-error';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    },

    /**
     * Clear validation error from field
     * @param {HTMLElement} field Input field
     */
    clearError(field) {
        field.classList.remove('input-error');
        const existingError = field.parentNode.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }
    },

    /**
     * Clear all validation errors in a form
     * @param {HTMLFormElement} form Form element
     */
    clearAllErrors(form) {
        form.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
        form.querySelectorAll('.validation-error').forEach(el => el.remove());
    }
};

// ===================================
// Search with Debouncing
// ===================================

/**
 * Initialize debounced search functionality
 * @param {string} inputSelector Selector for search input
 * @param {number} delay Debounce delay in ms
 */
function initDebouncedSearch(inputSelector = '.search-input', delay = 500) {
    const searchInput = document.querySelector(inputSelector);
    if (!searchInput) return;

    const form = searchInput.closest('form');
    if (!form) return;

    // Create a visual indicator for auto-search
    const indicator = document.createElement('span');
    indicator.className = 'search-indicator';
    indicator.style.cssText = 'display:none;margin-left:8px;color:#6c757d;font-size:12px;';
    searchInput.parentNode.appendChild(indicator);

    const debouncedSearch = debounce(() => {
        indicator.style.display = 'none';
        // Only auto-submit if there's meaningful input (3+ chars) or clearing
        if (searchInput.value.length >= 3 || searchInput.value.length === 0) {
            form.submit();
        }
    }, delay);

    searchInput.addEventListener('input', () => {
        if (searchInput.value.length >= 2) {
            indicator.textContent = 'Searching...';
            indicator.style.display = 'inline';
        }
        debouncedSearch();
    });

    // Prevent double submission on Enter
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            indicator.style.display = 'none';
            form.submit();
        }
    });
}

// ===================================
// Form Validation Setup
// ===================================

/**
 * Initialize form validation for add/edit student forms
 * @param {string} formSelector Selector for the form
 */
function initFormValidation(formSelector = '.add-student-form, .edit-student-form') {
    const form = document.querySelector(formSelector);
    if (!form) return;

    form.addEventListener('submit', function(e) {
        let isValid = true;
        Validator.clearAllErrors(form);

        // First name validation
        const firstName = form.querySelector('[name="first_name"]');
        if (firstName && !Validator.isRequired(firstName.value)) {
            Validator.showError(firstName, 'First name is required');
            isValid = false;
        }

        // Last name validation
        const lastName = form.querySelector('[name="last_name"]');
        if (lastName && !Validator.isRequired(lastName.value)) {
            Validator.showError(lastName, 'Last name is required');
            isValid = false;
        }

        // Email validation
        const email = form.querySelector('[name="email"]');
        if (email) {
            if (!Validator.isRequired(email.value)) {
                Validator.showError(email, 'Email is required');
                isValid = false;
            } else if (!Validator.isValidEmail(email.value)) {
                Validator.showError(email, 'Please enter a valid email address');
                isValid = false;
            }
        }

        // Date of birth validation
        const dob = form.querySelector('[name="date_of_birth"]');
        if (dob) {
            if (!Validator.isRequired(dob.value)) {
                Validator.showError(dob, 'Date of birth is required');
                isValid = false;
            } else if (!Validator.isValidBirthDate(dob.value)) {
                Validator.showError(dob, 'Please enter a valid date of birth (student must be 15-100 years old)');
                isValid = false;
            }
        }

        // Phone validation (optional but must be valid if provided)
        const phone = form.querySelector('[name="phone"]');
        if (phone && phone.value && !Validator.isValidPhone(phone.value)) {
            Validator.showError(phone, 'Please enter a valid phone number (e.g., 09123456789)');
            isValid = false;
        }

        // Gender validation
        const gender = form.querySelector('[name="gender"]');
        if (gender && !Validator.isRequired(gender.value)) {
            Validator.showError(gender, 'Please select a gender');
            isValid = false;
        }

        // Program validation
        const program = form.querySelector('[name="program_id"]');
        if (program && (!Validator.isRequired(program.value) || program.value === '0')) {
            Validator.showError(program, 'Please select a program');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();

            // If submit is blocked, ensure any page-level loading overlay is dismissed.
            const loadingOverlay = document.getElementById('loadingSpinner');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('active');
            }
            document.body.style.overflow = '';

            // Scroll to first error
            const firstError = form.querySelector('.input-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstError.focus();
            }
        }
    });

    // Real-time validation on blur
    const fieldsToValidate = ['first_name', 'last_name', 'email', 'phone', 'date_of_birth'];
    fieldsToValidate.forEach(fieldName => {
        const field = form.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.addEventListener('blur', function() {
                validateField(this);
            });
            field.addEventListener('input', function() {
                if (this.classList.contains('input-error')) {
                    validateField(this);
                }
            });
        }
    });
}

/**
 * Validate a single field
 * @param {HTMLElement} field Input field to validate
 */
function validateField(field) {
    const name = field.name;
    Validator.clearError(field);

    switch(name) {
        case 'first_name':
        case 'last_name':
            if (!Validator.isRequired(field.value)) {
                Validator.showError(field, `${name.replace('_', ' ')} is required`);
            }
            break;
        case 'email':
            if (field.value && !Validator.isValidEmail(field.value)) {
                Validator.showError(field, 'Please enter a valid email address');
            }
            break;
        case 'phone':
            if (field.value && !Validator.isValidPhone(field.value)) {
                Validator.showError(field, 'Please enter a valid phone number');
            }
            break;
        case 'date_of_birth':
            if (field.value && !Validator.isValidBirthDate(field.value)) {
                Validator.showError(field, 'Please enter a valid date of birth');
            }
            break;
    }
}

// ===================================
// Lazy Loading for Tables
// ===================================

/**
 * Initialize lazy loading for table rows (for large datasets)
 * @param {string} tableSelector Selector for the table
 * @param {number} batchSize Number of rows to show initially
 */
function initLazyTable(tableSelector = '.student-table', batchSize = 20) {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (rows.length <= batchSize) return;

    // Hide rows beyond batchSize
    rows.forEach((row, index) => {
        if (index >= batchSize) {
            row.style.display = 'none';
            row.dataset.lazy = 'true';
        }
    });

    // Add "Load More" button
    const loadMoreBtn = document.createElement('button');
    loadMoreBtn.className = 'btn btn-load-more';
    loadMoreBtn.textContent = `Load More (${rows.length - batchSize} remaining)`;
    loadMoreBtn.style.cssText = 'margin-top:20px;display:block;width:100%;';
    
    table.parentNode.appendChild(loadMoreBtn);

    let currentlyShown = batchSize;

    loadMoreBtn.addEventListener('click', () => {
        const hiddenRows = rows.filter(r => r.dataset.lazy === 'true' && r.style.display === 'none');
        const toShow = hiddenRows.slice(0, batchSize);
        
        toShow.forEach(row => {
            row.style.display = '';
        });

        currentlyShown += toShow.length;
        const remaining = rows.length - currentlyShown;

        if (remaining <= 0) {
            loadMoreBtn.remove();
        } else {
            loadMoreBtn.textContent = `Load More (${remaining} remaining)`;
        }
    });
}

// ===================================
// Performance Monitoring
// ===================================

const Performance = {
    /**
     * Measure and log page load time
     */
    measurePageLoad() {
        window.addEventListener('load', () => {
            setTimeout(() => {
                const timing = performance.timing;
                const loadTime = timing.loadEventEnd - timing.navigationStart;
                console.log(`Page load time: ${loadTime}ms`);
                
                // Store in sessionStorage for debugging
                const loadTimes = JSON.parse(sessionStorage.getItem('pageTimes') || '[]');
                loadTimes.push({
                    url: window.location.pathname,
                    time: loadTime,
                    timestamp: new Date().toISOString()
                });
                // Keep only last 10 measurements
                if (loadTimes.length > 10) loadTimes.shift();
                sessionStorage.setItem('pageTimes', JSON.stringify(loadTimes));
            }, 0);
        });
    },

    /**
     * Log performance metrics to console
     */
    logMetrics() {
        const loadTimes = JSON.parse(sessionStorage.getItem('pageTimes') || '[]');
        console.table(loadTimes);
    }
};

// ===================================
// Toast Notifications
// ===================================

const Toast = {
    /**
     * Show a toast notification
     * @param {string} message Message to display
     * @param {string} type Type: 'success', 'error', 'info'
     * @param {number} duration Duration in ms
     */
    show(message, type = 'info', duration = 5000) {
        // Remove existing toasts
        document.querySelectorAll('.toast-notification').forEach(t => t.remove());

        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message">${message}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        document.body.appendChild(toast);

        // Trigger animation
        setTimeout(() => toast.classList.add('toast-show'), 10);

        // Auto-hide
        setTimeout(() => {
            toast.classList.add('toast-hide');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
};

// ===================================
// Sidebar Navigation
// ===================================

function initSidebarNavigation() {
    const sidebar = document.getElementById('appSidebar');
    if (!sidebar) return;

    const body = document.body;
    const toggles = Array.from(document.querySelectorAll('[data-sidebar-toggle]'));
    const closeBtn = sidebar.querySelector('[data-sidebar-close]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const mobileQuery = window.matchMedia('(max-width: 1100px)');

    const syncExpandedState = () => {
        const isOpen = body.classList.contains('sidebar-open');
        toggles.forEach((btn) => {
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        if (mobileQuery.matches) {
            sidebar.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            if (backdrop) {
                backdrop.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            }
        } else {
            sidebar.setAttribute('aria-hidden', 'false');
            if (backdrop) {
                backdrop.setAttribute('aria-hidden', 'true');
            }
        }
    };

    const closeSidebar = () => {
        body.classList.remove('sidebar-open');
        syncExpandedState();
    };

    const syncForViewport = () => {
        if (!mobileQuery.matches) {
            body.classList.remove('sidebar-open');
        }
        syncExpandedState();
    };

    toggles.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!mobileQuery.matches) return;

            body.classList.toggle('sidebar-open');
            syncExpandedState();
        });
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileQuery.matches) {
                closeSidebar();
            }
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', (event) => {
            if (!event.matches) {
                closeSidebar();
                return;
            }

            syncExpandedState();
        });
    } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(syncForViewport);
    }

    syncForViewport();
}

// ===================================
// Accessibility Enhancements
// ===================================

/**
 * Create a skip link and attach it to the first major content container.
 */
function initSkipLink() {
    if (document.querySelector('.skip-link')) {
        return;
    }

    const mainTarget = document.querySelector('main') || document.querySelector('.container');
    if (!mainTarget) {
        return;
    }

    if (!mainTarget.id) {
        mainTarget.id = 'main-content';
    }

    if (mainTarget.tagName.toLowerCase() !== 'main' && !mainTarget.hasAttribute('role')) {
        mainTarget.setAttribute('role', 'main');
    }

    const skipLink = document.createElement('a');
    skipLink.className = 'skip-link';
    skipLink.href = `#${mainTarget.id}`;
    skipLink.textContent = 'Skip to main content';
    document.body.insertBefore(skipLink, document.body.firstChild);
}

/**
 * @param {Element} element
 * @returns {boolean}
 */
function isNativeInteractiveElement(element) {
    const tagName = element.tagName;
    if (['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'SUMMARY', 'OPTION'].includes(tagName)) {
        return true;
    }

    return element.hasAttribute('contenteditable');
}

/**
 * @param {HTMLElement} element
 */
function bindKeyboardClick(element) {
    if (element.dataset.keyboardClickBound === 'true') {
        return;
    }

    element.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            element.click();
        }
    });

    element.dataset.keyboardClickBound = 'true';
}

/**
 * Add keyboard access to click-only UI controls.
 */
function initKeyboardAccessibleActions() {
    const clickTargets = Array.from(document.querySelectorAll('[onclick]'));

    clickTargets.forEach((target) => {
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (isNativeInteractiveElement(target)) {
            return;
        }

        if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '0');
        }

        if (!target.hasAttribute('role')) {
            target.setAttribute('role', 'button');
        }

        bindKeyboardClick(target);
    });

    const statusCards = Array.from(document.querySelectorAll('.status-card[onclick]'));
    if (statusCards.length > 0) {
        const syncPressedState = () => {
            statusCards.forEach((card) => {
                card.setAttribute('aria-pressed', card.classList.contains('active') ? 'true' : 'false');
            });
        };

        statusCards.forEach((card) => {
            card.addEventListener('click', () => {
                setTimeout(syncPressedState, 0);
            });
        });

        syncPressedState();
    }
}

let tabListCounter = 0;

/**
 * @param {HTMLElement} tab
 * @returns {string}
 */
function resolveTabPanelId(tab) {
    const dataTabValue = tab.getAttribute('data-tab');
    if (dataTabValue) {
        const panelIdFromData = `tab-${dataTabValue}`;
        if (document.getElementById(panelIdFromData)) {
            return panelIdFromData;
        }
    }

    const onclickValue = tab.getAttribute('onclick') || '';
    const match = onclickValue.match(/showTab\(['"]([^'"]+)['"]\)/);
    if (match && match[1]) {
        const panelId = `tab-${match[1]}`;
        if (document.getElementById(panelId)) {
            return panelId;
        }
    }

    if (tab instanceof HTMLAnchorElement) {
        const href = tab.getAttribute('href') || '';
        const tabQuery = href.match(/[?&]tab=([^&]+)/);
        if (tabQuery && tabQuery[1]) {
            const panelId = `tab-${decodeURIComponent(tabQuery[1])}`;
            if (document.getElementById(panelId)) {
                return panelId;
            }
        }
    }

    return '';
}

/**
 * @param {HTMLElement} tabList
 * @param {HTMLElement[]} tabs
 */
function enhanceTabList(tabList, tabs) {
    if (tabs.length < 2) {
        return;
    }

    tabListCounter += 1;
    tabList.setAttribute('role', 'tablist');

    const syncTabState = () => {
        let activeIndex = tabs.findIndex((tab) => tab.classList.contains('active'));
        if (activeIndex < 0) {
            activeIndex = 0;
        }

        tabs.forEach((tab, index) => {
            const isSelected = index === activeIndex;
            tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            tab.setAttribute('tabindex', isSelected ? '0' : '-1');
        });
    };

    tabs.forEach((tab, index) => {
        if (!tab.id) {
            tab.id = `tab-control-${tabListCounter}-${index}`;
        }

        tab.setAttribute('role', 'tab');

        const panelId = resolveTabPanelId(tab);
        if (panelId) {
            tab.setAttribute('aria-controls', panelId);
            const panel = document.getElementById(panelId);
            if (panel) {
                panel.setAttribute('role', 'tabpanel');
                panel.setAttribute('aria-labelledby', tab.id);
            }
        }

        tab.addEventListener('click', () => {
            setTimeout(syncTabState, 0);
        });

        tab.addEventListener('keydown', (event) => {
            const maxIndex = tabs.length - 1;
            let nextIndex = index;

            if (event.key === 'ArrowRight') {
                nextIndex = index === maxIndex ? 0 : index + 1;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = index === 0 ? maxIndex : index - 1;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = maxIndex;
            } else {
                return;
            }

            event.preventDefault();
            const nextTab = tabs[nextIndex];
            if (!nextTab) {
                return;
            }

            nextTab.focus();
            nextTab.click();
        });
    });

    syncTabState();
}

/**
 * Attach semantic tab roles and keyboard nav to existing tab UIs.
 */
function initSemanticTabs() {
    document.querySelectorAll('.dashboard-tabs').forEach((tabList) => {
        const tabs = Array.from(tabList.querySelectorAll('.dashboard-tab'));
        enhanceTabList(tabList, tabs);
    });

    document.querySelectorAll('.tabs').forEach((tabList) => {
        const tabs = Array.from(tabList.querySelectorAll(':scope > .tab, :scope > .tab-link'));
        enhanceTabList(tabList, tabs);
    });
}

/**
 * Apply missing ARIA dialog semantics to existing modal markup.
 */
function initDialogSemantics() {
    const overlays = Array.from(document.querySelectorAll('.modal-overlay'));
    overlays.forEach((overlay) => {
        if (!(overlay instanceof HTMLElement)) {
            return;
        }

        const dialog = overlay.querySelector('.modal, .modal-container');
        if (!(dialog instanceof HTMLElement)) {
            return;
        }

        overlay.setAttribute('role', 'presentation');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');

        if (!dialog.hasAttribute('tabindex')) {
            dialog.setAttribute('tabindex', '-1');
        }
    });
}

let confirmDialogState = null;

/**
 * @returns {{overlay: HTMLElement, dialog: HTMLElement, title: HTMLElement, message: HTMLElement, cancelBtn: HTMLButtonElement, confirmBtn: HTMLButtonElement, resolver: ((value: boolean) => void)|null, restoreFocusEl: Element|null}}
 */
function ensureConfirmDialog() {
    if (confirmDialogState) {
        return confirmDialogState;
    }

    const overlay = document.createElement('div');
    overlay.id = 'appConfirmOverlay';
    overlay.className = 'modal-overlay app-confirm-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="appConfirmTitle" aria-describedby="appConfirmMessage" tabindex="-1">
            <div class="modal-header">
                <h3 id="appConfirmTitle" class="modal-title">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p id="appConfirmMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-confirm-cancel>Cancel</button>
                <button type="button" class="btn btn-danger" data-confirm-accept>Confirm</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const dialog = overlay.querySelector('.modal');
    const title = overlay.querySelector('#appConfirmTitle');
    const message = overlay.querySelector('#appConfirmMessage');
    const cancelBtn = overlay.querySelector('[data-confirm-cancel]');
    const confirmBtn = overlay.querySelector('[data-confirm-accept]');

    if (!(dialog instanceof HTMLElement) || !(title instanceof HTMLElement) || !(message instanceof HTMLElement) || !(cancelBtn instanceof HTMLButtonElement) || !(confirmBtn instanceof HTMLButtonElement)) {
        throw new Error('Unable to initialize shared confirm dialog.');
    }

    confirmDialogState = {
        overlay,
        dialog,
        title,
        message,
        cancelBtn,
        confirmBtn,
        resolver: null,
        restoreFocusEl: null,
    };

    const getFocusableElements = () => {
        return Array.from(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
            .filter((el) => el instanceof HTMLElement && !el.hasAttribute('disabled') && el.getAttribute('aria-hidden') !== 'true');
    };

    const closeDialog = (confirmed) => {
        if (!confirmDialogState) {
            return;
        }

        confirmDialogState.overlay.classList.remove('active');
        confirmDialogState.overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('confirm-modal-open');

        if (confirmDialogState.restoreFocusEl instanceof HTMLElement) {
            confirmDialogState.restoreFocusEl.focus();
        }

        const resolver = confirmDialogState.resolver;
        confirmDialogState.resolver = null;
        confirmDialogState.restoreFocusEl = null;

        if (typeof resolver === 'function') {
            resolver(confirmed);
        }
    };

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeDialog(false);
        }
    });

    cancelBtn.addEventListener('click', () => closeDialog(false));
    confirmBtn.addEventListener('click', () => closeDialog(true));

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog(false);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusables = getFocusableElements();
        if (focusables.length === 0) {
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    });

    return confirmDialogState;
}

/**
 * Show a shared confirmation dialog.
 * @param {string} message
 * @param {Object} options
 * @returns {Promise<boolean>}
 */
function showConfirmDialog(message, options = {}) {
    const state = ensureConfirmDialog();

    if (state.resolver) {
        state.resolver(false);
        state.resolver = null;
    }

    state.restoreFocusEl = document.activeElement;

    state.title.textContent = options.title || 'Confirm Action';
    state.message.textContent = message || 'Are you sure you want to continue?';
    state.cancelBtn.textContent = options.cancelText || 'Cancel';
    state.confirmBtn.textContent = options.confirmText || 'Confirm';

    const confirmClass = options.confirmClass || 'btn btn-danger';
    state.confirmBtn.className = confirmClass;

    state.overlay.classList.add('active');
    state.overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('confirm-modal-open');

    setTimeout(() => state.confirmBtn.focus(), 0);

    return new Promise((resolve) => {
        state.resolver = resolve;
    });
}

/**
 * Enable declarative confirmation using data-confirm attributes on forms.
 */
function initDeclarativeConfirmForms() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const message = form.getAttribute('data-confirm');
        if (!message) {
            return;
        }

        if (form.dataset.confirmBypass === 'true') {
            delete form.dataset.confirmBypass;
            return;
        }

        event.preventDefault();

        const confirmText = form.getAttribute('data-confirm-text') || 'Confirm';
        const cancelText = form.getAttribute('data-cancel-text') || 'Cancel';
        const confirmStyle = form.getAttribute('data-confirm-style') || 'danger';
        const confirmClass = confirmStyle === 'primary' ? 'btn btn-primary' : 'btn btn-danger';
        const title = form.getAttribute('data-confirm-title') || 'Please Confirm';

        showConfirmDialog(message, {
            title,
            confirmText,
            cancelText,
            confirmClass,
        }).then((confirmed) => {
            if (!confirmed) {
                return;
            }

            form.dataset.confirmBypass = 'true';

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        });
    }, true);
}

// ===================================
// Initialize on DOM Ready
// ===================================

document.addEventListener('DOMContentLoaded', function() {
    ensureSiteFavicon();
    initSkipLink();
    initSemanticTabs();
    initKeyboardAccessibleActions();
    initDialogSemantics();
    initDeclarativeConfirmForms();
    initSidebarNavigation();

    // Initialize debounced search
    initDebouncedSearch('.search-input', 500);
    
    // Initialize form validation
    initFormValidation('.add-student-form');
    initFormValidation('.edit-student-form');
    
    // Measure page load performance
    Performance.measurePageLoad();
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K for search focus
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.querySelector('.search-input');
            if (searchInput) searchInput.focus();
        }
    });
});

// ===================================
// Drop Student Confirmation
// ===================================

/**
 * Redirect to drop student confirmation page
 * @param {number} id Student ID
 * @param {string} name Student Name
 */
function confirmDrop(id, name) {
    // Redirect to the confirmation page logic (interstitial)
    window.location.href = `pages/drop_student.php?id=${id}`;
}

// Export for use in other scripts
window.StudentApp = {
    Validator,
    Toast,
    Performance,
    debounce,
    throttle,
    showConfirmDialog,
    showApiErrorNotice,
    clearApiErrorNotice
};
