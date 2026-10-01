/* ==========================================================================
   UniGo - shared client runtime
   --------------------------------------------------------------------------
   Vanilla ES2018, no framework, no build step. Everything is exposed on
   window.UniGo so inline page scripts and modules can reuse it:

     UniGo.icon(name, classes)          build an <svg> icon element
     UniGo.api(path, options)           JSON fetch with CSRF + error toasts
     UniGo.toast(message, type)         transient notification
     UniGo.confirm(opts) -> Promise     accessible confirm dialog
     UniGo.fmt.money / date / time      display helpers
     UniGo.format / debounce / escape   small utilities
     UniGo.poll(element, url, opts)     lightweight live region refresher
   ========================================================================== */
(function (window, document) {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    var UniGo = {
        config: window.UNIGO || {},
        instances: {}
    };

    /* ------------------------------------------------------------------
     * Utilities
     * ---------------------------------------------------------------- */
    UniGo.qs  = function (sel, root) { return (root || document).querySelector(sel); };
    UniGo.qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    UniGo.on  = function (el, type, handler, opts) { if (el) el.addEventListener(type, handler, opts || false); return el; };

    UniGo.debounce = function (fn, wait) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            window.clearTimeout(t);
            t = window.setTimeout(function () { fn.apply(ctx, args); }, wait || 250);
        };
    };

    UniGo.escape = function (value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    UniGo.format = {
        money: function (amount, symbol) {
            var n = Number(amount || 0);
            var text = n.toLocaleString('en-US', { maximumFractionDigits: 0 });
            return symbol ? (symbol + ' ' + text) : text;
        },
        number: function (n) { return Number(n || 0).toLocaleString('en-US'); },
        short: function (n) {
            n = Number(n || 0);
            if (n >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
            if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
            return String(n);
        },
        date: function (value, opts) {
            if (!value) return '-';
            var d = new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return '-';
            return d.toLocaleDateString('en-GB', opts || { day: 'numeric', month: 'short', year: 'numeric' });
        },
        time: function (value) {
            if (!value) return '-';
            var d = new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return '-';
            return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        },
        datetime: function (value) {
            if (!value) return '-';
            var d = new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return '-';
            return UniGo.format.date(d.toISOString()) + ', ' + UniGo.format.time(d.toISOString());
        },
        ago: function (value) {
            if (!value) return '-';
            var d = new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return '-';
            var diff = Math.floor((Date.now() - d.getTime()) / 1000);
            if (diff < 5) return 'just now';
            if (diff < 60) return diff + ' sec ago';
            if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
            if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
            if (diff < 604800) return Math.floor(diff / 86400) + ' d ago';
            return UniGo.format.date(d.toISOString());
        },
        duration: function (minutes) {
            var m = Math.max(0, parseInt(minutes, 10) || 0);
            var h = Math.floor(m / 60), r = m % 60;
            return h === 0 ? r + 'm' : h + 'h' + (r > 0 ? ' ' + r + 'm' : '');
        },
        initials: function (name) {
            var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) return '?';
            return ((parts[0][0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
        }
    };

    /* ------------------------------------------------------------------
     * Icons - swap <i class="icon">name</i> for <svg><use href="#i-name">
     * ---------------------------------------------------------------- */
    UniGo.icon = function (name, className) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'icon' + (className ? ' ' + className : ''));
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        var use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', '#i-' + name);
        use.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', '#i-' + name);
        svg.appendChild(use);
        return svg;
    };

    UniGo.upgradeIcons = function (root) {
        var nodes = (root || document).querySelectorAll('i.icon[data-icon], i.icon:not([data-icon])');
        Array.prototype.forEach.call(nodes, function (el) {
            if (el.getAttribute('data-icon-done') === '1') return;
            var name = (el.getAttribute('data-icon') || el.textContent || '').trim();
            if (!name) return;
            var svg = UniGo.icon(name, (el.getAttribute('class') || '').replace(/\bicon\b/, '').trim());
            if (el.getAttribute('aria-label')) svg.setAttribute('aria-label', el.getAttribute('aria-label'));
            el.parentNode.replaceChild(svg, el);
        });
    };

    /* ------------------------------------------------------------------
     * Toasts
     * ---------------------------------------------------------------- */
    var toastIcons = {
        success: 'check-circle',
        error: 'alert',
        warning: 'alert',
        info: 'info'
    };

    UniGo.toast = function (message, type, title, timeout) {
        type = toastIcons[type] ? type : 'info';
        var stack = UniGo.qs('#toastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.id = 'toastStack';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }

        var toast = document.createElement('div');
        toast.className = 'toast toast--' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var icon = UniGo.icon(toastIcons[type], 'toast__icon');
        var body = document.createElement('div');
        body.className = 'toast__body';
        if (title) {
            var t = document.createElement('p');
            t.className = 'toast__title';
            t.textContent = title;
            body.appendChild(t);
        }
        var p = document.createElement('p');
        p.className = 'toast__text';
        p.textContent = String(message || '');
        body.appendChild(p);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('aria-label', 'Dismiss');
        close.appendChild(UniGo.icon('x', 'icon--sm'));
        close.addEventListener('click', function () { dismiss(); });

        toast.appendChild(icon);
        toast.appendChild(body);
        toast.appendChild(close);
        stack.appendChild(toast);

        var timer = window.setTimeout(dismiss, timeout || (type === 'error' ? 8000 : 4500));
        function dismiss() {
            window.clearTimeout(timer);
            if (!toast.parentNode) return;
            toast.classList.add('is-out');
            window.setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 200);
        }
        return dismiss;
    };

    /* ------------------------------------------------------------------
     * Confirm dialog (built on demand, focus returned afterwards)
     * ---------------------------------------------------------------- */
    UniGo.confirm = function (options) {
        options = options || {};
        return new Promise(function (resolve) {
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop';
            backdrop.innerHTML =
                '<div class="modal modal--sm" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle">' +
                    '<div class="modal__header">' +
                        '<h2 class="modal__title" id="confirmTitle"></h2>' +
                        '<button type="button" class="modal__close" data-close aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal__body"><p class="text-muted-2"></p></div>' +
                    '<div class="modal__footer">' +
                        '<button type="button" class="btn btn--light" data-close data-cancel></button>' +
                        '<button type="button" class="btn" data-ok></button>' +
                    '</div>' +
                '</div>';

            backdrop.querySelector('#confirmTitle').textContent = options.title || 'Are you sure?';
            backdrop.querySelector('.modal__body p').textContent = options.message || 'This action cannot be undone.';
            var okBtn = backdrop.querySelector('[data-ok]');
            okBtn.textContent = options.confirmText || 'Yes, continue';
            okBtn.className = 'btn btn--' + (options.danger === false ? 'primary' : 'danger');
            backdrop.querySelector('[data-cancel]').textContent = options.cancelText || 'Cancel';
            backdrop.querySelector('.modal__close').appendChild(UniGo.icon('x'));

            var previouslyFocused = document.activeElement;

            function close(result) {
                if (!UniGo.qs('.modal-backdrop.is-open')) document.body.classList.remove('is-locked');
                document.removeEventListener('keydown', onKey);
                if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
                if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus();
                resolve(result);
            }
            function onKey(ev) {
                if (ev.key === 'Escape') { ev.preventDefault(); close(false); }
                if (ev.key === 'Tab') {
                    var focusables = UniGo.qsa('button, [href], input, select, textarea', backdrop);
                    if (!focusables.length) return;
                    var first = focusables[0], last = focusables[focusables.length - 1];
                    if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
                    else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
                }
            }

            okBtn.addEventListener('click', function () { close(true); });
            UniGo.qsa('[data-close]', backdrop).forEach(function (b) {
                b.addEventListener('click', function () { close(false); });
            });
            backdrop.addEventListener('mousedown', function (ev) {
                if (ev.target === backdrop) close(false);
            });
            document.addEventListener('keydown', onKey);
            document.body.appendChild(backdrop);
            document.body.classList.add('is-locked');
            window.requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
            okBtn.focus();
        });
    };

    /* ------------------------------------------------------------------
     * JSON API helper
     * ---------------------------------------------------------------- */
    UniGo.api = function (path, options) {
        options = options || {};
        var url = path.indexOf('http') === 0 ? path : UniGo.config.apiUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
        var opts = {
            method: (options.method || 'GET').toUpperCase(),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': UniGo.config.csrf || ''
            },
            credentials: 'same-origin'
        };

        if (options.body !== undefined && options.body !== null) {
            if (typeof options.body === 'string' || options.body instanceof FormData) {
                opts.body = options.body;
            } else {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(options.body);
            }
        }
        if (opts.method !== 'GET' && opts.method !== 'HEAD') {
            opts.headers['X-CSRF-Token'] = UniGo.config.csrf || '';
        }

        return window.fetch(url, opts).then(function (res) {
            if (res.status === 419) {
                UniGo.toast('Your session expired. Refreshing the page...', 'warning');
                window.setTimeout(function () { window.location.reload(); }, 1500);
                throw new Error('Session expired');
            }
            if (res.status === 204) return { success: true, data: null };

            return res.text().then(function (raw) {
                var payload = null;
                try { payload = raw ? JSON.parse(raw) : null; } catch (e) { payload = null; }
                if (!res.ok) {
                    var err = new Error((payload && payload.message) || 'Request failed (' + res.status + ')');
                    err.status = res.status;
                    err.payload = payload;
                    if (!options.silent) {
                        var first = '';
                        if (payload && payload.errors) {
                            first = Object.keys(payload.errors).map(function (k) { return payload.errors[k]; })[0];
                            if (Array.isArray(first)) first = first[0];
                        }
                        UniGo.toast(first || err.message, 'error');
                    }
                    throw err;
                }
                return payload || { success: true, data: null };
            });
        }).catch(function (err) {
            if (err instanceof TypeError && !options.silent) {
                UniGo.toast('Network problem. Check your connection and try again.', 'error');
            }
            throw err;
        });
    };

    /* ------------------------------------------------------------------
     * Sidebar drawer
     * ---------------------------------------------------------------- */
    function setupSidebar() {
        var sidebar = UniGo.qs('#sidebar');
        var toggle = UniGo.qs('#sidebarToggle');
        var backdrop = UniGo.qs('#sidebarBackdrop');
        if (!sidebar || !toggle) return;

        function open() {
            sidebar.classList.add('is-open');
            if (backdrop) backdrop.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('is-locked');
        }
        function close() {
            sidebar.classList.remove('is-open');
            if (backdrop) backdrop.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('is-locked');
        }
        function sync() {
            if (window.matchMedia('(min-width: 768px)').matches) {
                sidebar.classList.remove('is-open');
                if (backdrop) backdrop.classList.remove('is-open');
                document.body.classList.remove('is-locked');
            } else if (!sidebar.classList.contains('is-open')) {
                document.body.classList.remove('is-locked');
            }
        }

        toggle.addEventListener('click', function () {
            sidebar.classList.contains('is-open') ? close() : open();
        });
        if (backdrop) backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && sidebar.classList.contains('is-open')) close();
        });
        window.addEventListener('resize', UniGo.debounce(sync, 150));
        sync();
    }

    /* ------------------------------------------------------------------
     * Dropdowns
     * ---------------------------------------------------------------- */
    function setupDropdowns() {
        UniGo.qsa('.dropdown').forEach(function (dd) {
            var trigger = dd.querySelector('[aria-haspopup], .btn, button');
            if (!trigger) return;
            // An anchor trigger (notification bell, account menu) opens the
            // preview instead of navigating away from the page.
            var isLink = trigger.tagName === 'A';
            trigger.setAttribute('aria-expanded', 'false');

            trigger.addEventListener('click', function (ev) {
                ev.stopPropagation();
                if (isLink) ev.preventDefault();
                var isOpen = dd.classList.contains('is-open');
                closeAllDropdowns();
                if (!isOpen) {
                    dd.classList.add('is-open');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });

            // Following an item inside the menu should close it.
            dd.addEventListener('click', function (ev) {
                if (ev.target.closest('.dropdown__item')) closeAllDropdowns();
            });
        });
        document.addEventListener('click', closeAllDropdowns);
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') closeAllDropdowns();
        });
    }
    function closeAllDropdowns() {
        UniGo.qsa('.dropdown.is-open').forEach(function (dd) {
            dd.classList.remove('is-open');
            var t = dd.querySelector('[aria-haspopup], .btn, button');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }

    /* ------------------------------------------------------------------
     * Tabs / segmented controls
     * ---------------------------------------------------------------- */
    function setupTabs() {
        UniGo.qsa('[data-tabs]').forEach(function (group) {
            group.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-tab]');
                if (!btn || !group.contains(btn)) return;
                ev.preventDefault();
                var name = btn.getAttribute('data-tab');
                var scope = group.getAttribute('data-tabs');
                var target = scope ? document.getElementById(scope) : null;

                UniGo.qsa('[data-tab]', group).forEach(function (b) {
                    var on = b === btn;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                    if (on && b.tagName === 'A') {
                        var href = b.getAttribute('href');
                        if (href && href.charAt(0) === '#') {
                            var hash = b.getAttribute('href').slice(1);
                            window.history.replaceState(null, '', '#' + hash);
                        }
                    }
                });
                if (!target) return;

                var panels = UniGo.qsa('[data-tab-panel]', target.parentNode || document);
                panels.forEach(function (p) {
                    p.hidden = p.getAttribute('data-tab-panel') !== name;
                });
            });
        });

        // Open the panel referenced by the URL hash.
        if (window.location.hash.length > 1) {
            var btn = UniGo.qsa('[data-tab]').filter(function (b) {
                return (b.getAttribute('href') || '').replace('#', '') === window.location.hash.slice(1);
            })[0];
            if (btn) btn.click();
        }
    }

    /* ------------------------------------------------------------------
     * Modals
     * ---------------------------------------------------------------- */
    function setupModals() {
        var openCount = 0;
        UniGo.openModal = function (id) {
            var modal = typeof id === 'string' ? document.getElementById(id) : id;
            if (!modal) return;
            modal.classList.add('is-open');
            modal.removeAttribute('aria-hidden');
            document.body.classList.add('is-locked');
            openCount++;
            var focusable = modal.querySelector('input:not([type=hidden]), select, textarea, button');
            if (focusable) focusable.focus();
        };
        UniGo.closeModal = function (id) {
            var modal = typeof id === 'string' ? document.getElementById(id) : id;
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if (openCount > 0) openCount--;
            if (openCount === 0) document.body.classList.remove('is-locked');
        };

        document.addEventListener('click', function (ev) {
            var opener = ev.target.closest('[data-modal-open]');
            if (opener) {
                ev.preventDefault();
                UniGo.openModal(opener.getAttribute('data-modal-open'));
                return;
            }
            var closer = ev.target.closest('[data-modal-close]');
            if (closer) {
                ev.preventDefault();
                UniGo.closeModal(closer.closest('.modal-backdrop'));
                return;
            }
            if (ev.target.classList && ev.target.classList.contains('modal-backdrop')) {
                UniGo.closeModal(ev.target);
            }
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') return;
            var open = UniGo.qsa('.modal-backdrop.is-open');
            if (open.length) UniGo.closeModal(open[open.length - 1]);
        });
    }

    /* ------------------------------------------------------------------
     * Confirm on submit / click
     * ---------------------------------------------------------------- */
    function setupConfirms() {
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            var message = form.getAttribute('data-confirm');
            if (!message || form.dataset.confirmed === '1') return;
            ev.preventDefault();
            UniGo.confirm({
                title: form.getAttribute('data-confirm-title') || 'Please confirm',
                message: message,
                confirmText: form.getAttribute('data-confirm-text') || 'Yes, continue',
                danger: form.getAttribute('data-confirm-danger') !== 'false'
            }).then(function (ok) {
                if (!ok) return;
                form.dataset.confirmed = '1';
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        });

        document.addEventListener('click', function (ev) {
            var link = ev.target.closest('a[data-confirm]');
            if (!link) return;
            ev.preventDefault();
            UniGo.confirm({ message: link.getAttribute('data-confirm') }).then(function (ok) {
                if (ok) window.location.href = link.getAttribute('href');
            });
        });
    }

    /* ------------------------------------------------------------------
     * Buttons: loading state, double submit guard
     * ---------------------------------------------------------------- */
    function setupButtons() {
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-loading-text]');
            if (!btn) return;
            if (btn.dataset.busy === '1') { ev.preventDefault(); return; }
            btn.dataset.busy = '1';
            btn.classList.add('is-loading');
            var label = btn.innerHTML;
            btn.dataset.originalHtml = label;
            btn.innerHTML = '';
            btn.appendChild(document.createTextNode(btn.getAttribute('data-loading-text') || 'Working...'));
        });
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (form.dataset.busy === '1') { ev.preventDefault(); return; }
            form.dataset.busy = '1';
            var btn = form.querySelector('[type=submit]');
            if (!btn) return;
            if (btn.getAttribute('data-loading-text')) return; // click handler already did it
            btn.classList.add('is-loading');
        });
    }

    /* ------------------------------------------------------------------
     * Alerts (dismiss + auto hide)
     * ---------------------------------------------------------------- */
    function setupAlerts() {
        document.addEventListener('click', function (ev) {
            var close = ev.target.closest('[data-alert-close]');
            if (!close) return;
            var alert = close.closest('.alert');
            if (alert && alert.parentNode) alert.parentNode.removeChild(alert);
        });
        UniGo.qsa('[data-autodismiss]').forEach(function (el) {
            var ms = parseInt(el.getAttribute('data-autodismiss'), 10);
            if (!ms) return;
            window.setTimeout(function () {
                var close = el.querySelector('[data-alert-close]');
                if (close) close.click();
            }, ms);
        });
    }

    /* ------------------------------------------------------------------
     * Password helpers
     * ---------------------------------------------------------------- */
    function setupPasswords() {
        UniGo.qsa('[data-toggle-password]').forEach(function (btn) {
            var input = document.querySelector(btn.getAttribute('data-toggle-password'));
            if (!input) return;
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                var icon = btn.querySelector('svg, i');
                if (icon) icon.setAttribute('href', '#i-' + (show ? 'eye-off' : 'eye'));
            });
        });

        UniGo.qsa('[data-strength]').forEach(function (meter) {
            var input = document.querySelector(meter.getAttribute('data-strength'));
            if (!input) return;
            var bars = UniGo.qsa('.pw-strength__bar', meter);
            input.addEventListener('input', function () {
                var v = input.value;
                var score = 0;
                if (v.length >= 8) score++;
                if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
                if (/\d/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) score++;
                bars.forEach(function (bar, i) {
                    bar.className = 'pw-strength__bar' + (i < score ? ' is-on-' + score : '');
                });
                var label = UniGo.qs('[data-strength-label]', meter.parentNode);
                if (label) {
                    var text = ['', 'Weak', 'Fair', 'Good', 'Strong'][score];
                    label.textContent = v ? text : '';
                }
            });
        });
    }

    /* ------------------------------------------------------------------
     * Autocomplete / suggest boxes
     * ---------------------------------------------------------------- */
    function setupSuggest() {
        UniGo.qsa('[data-suggest]').forEach(function (input) {
            var box = document.querySelector(input.getAttribute('data-suggest'));
            if (!box) return;
            var url = input.getAttribute('data-suggest-url') || '';
            var minChars = parseInt(input.getAttribute('data-suggest-min') || '2', 10);
            var hidden = input.getAttribute('data-suggest-value') ? document.querySelector(input.getAttribute('data-suggest-value')) : null;
            var active = -1;

            function render(items) {
                active = -1;
                UniGo.suggestCache = items;
                if (!items.length) {
                    box.innerHTML = '<div class="suggest__empty">No matches found</div>';
                    box.classList.add('is-open');
                    return;
                }
                box.innerHTML = items.map(function (item, i) {
                    return '<div class="suggest__item" role="option" data-index="' + i + '">' +
                        UniGo.icon('pin', 'icon--sm').outerHTML +
                        '<span><span class="suggest__label">' + UniGo.escape(item.label) + '</span>' +
                        (item.meta ? '<br><span class="suggest__meta">' + UniGo.escape(item.meta) + '</span>' : '') +
                        '</span></div>';
                }).join('');
                box.classList.add('is-open');
            }

            function choose(item) {
                input.value = item.label;
                if (hidden) hidden.value = item.id;
                box.classList.remove('is-open');
                input.dispatchEvent(new CustomEvent('suggest:select', { detail: item, bubbles: true }));
            }

            var search = UniGo.debounce(function () {
                var term = input.value.trim();
                if (term.length < minChars) { box.classList.remove('is-open'); return; }
                if (!url) return;
                UniGo.api(url + encodeURIComponent(term), { silent: true })
                    .then(function (res) { render((res && res.data) || []); })
                    .catch(function () { box.classList.remove('is-open'); });
            }, 220);

            input.addEventListener('input', search);
            input.addEventListener('focus', function () { if (input.value.trim().length >= minChars) search(); });
            input.addEventListener('blur', function () {
                window.setTimeout(function () { box.classList.remove('is-open'); }, 160);
            });
            input.addEventListener('keydown', function (ev) {
                var items = UniGo.qsa('.suggest__item', box);
                if (!items.length) return;
                if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    active += ev.key === 'ArrowDown' ? 1 : -1;
                    if (active < 0) active = items.length - 1;
                    if (active >= items.length) active = 0;
                    items.forEach(function (el, i) { el.classList.toggle('is-active', i === active); });
                } else if (ev.key === 'Enter' && active >= 0) {
                    ev.preventDefault();
                    items[active].click();
                } else if (ev.key === 'Escape') {
                    box.classList.remove('is-open');
                }
            });
            var pickedByMouse = false;
            box.addEventListener('mousedown', function (ev) {
                var item = ev.target.closest('.suggest__item');
                if (!item) return;
                ev.preventDefault();
                var index = parseInt(item.getAttribute('data-index'), 10);
                var payload = UniGo.suggestCache && UniGo.suggestCache[index];
                pickedByMouse = true;
                window.setTimeout(function () { pickedByMouse = false; }, 200);
                if (payload) choose(payload);
            });
            box.addEventListener('click', function (ev) {
                if (pickedByMouse) return;
                var item = ev.target.closest('.suggest__item');
                if (!item) return;
                var index = parseInt(item.getAttribute('data-index'), 10);
                var payload = UniGo.suggestCache && UniGo.suggestCache[index];
                if (payload) choose(payload);
            });
        });
    }

    /* ------------------------------------------------------------------
     * Filter forms: auto submit, chips, select all
     * ---------------------------------------------------------------- */
    function setupFilters() {
        UniGo.qsa('[data-autosubmit]').forEach(function (form) {
            var delay = parseInt(form.getAttribute('data-autosubmit'), 10) || 400;
            var trigger = function () {
                window.clearTimeout(form._uniGoTimer);
                form._uniGoTimer = window.setTimeout(function () { form.submit(); }, delay);
            };
            UniGo.qsa('select, input[type=checkbox], input[type=radio], input[type=date]', form).forEach(function (field) {
                field.addEventListener('change', trigger);
            });
            UniGo.qsa('input[type=search], input[type=text]', form).forEach(function (field) {
                field.addEventListener('input', UniGo.debounce(trigger, delay));
            });
        });

        UniGo.qsa('[data-chip-group]').forEach(function (group) {
            group.addEventListener('click', function (ev) {
                var chip = ev.target.closest('.chip');
                if (!chip) return;
                ev.preventDefault();
                var name = group.getAttribute('data-chip-group');
                var input = document.querySelector('[name="' + name + '"]');
                if (input) input.value = chip.getAttribute('data-value') || chip.textContent.trim();
                UniGo.qsa('.chip', group).forEach(function (c) { c.classList.toggle('is-active', c === chip); });
                var form = input ? input.form : null;
                if (form) form.submit();
            });
        });

        UniGo.qsa('[data-check-all]').forEach(function (master) {
            var scope = document.getElementById(master.getAttribute('data-check-all').replace('#', '')) || document;
            master.addEventListener('change', function () {
                UniGo.qsa('input[type=checkbox][name="' + (master.getAttribute('name') || '') + '"]', scope)
                    .forEach(function (cb) { cb.checked = master.checked; });
                document.dispatchEvent(new CustomEvent('uni:selection', { detail: { checked: master.checked } }));
            });
        });
    }

    /* ------------------------------------------------------------------
     * Copy to clipboard, print
     * ---------------------------------------------------------------- */
    function setupMisc() {
        document.addEventListener('click', function (ev) {
            var copy = ev.target.closest('[data-copy]');
            if (copy) {
                ev.preventDefault();
                var value = copy.getAttribute('data-copy') || '';
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(value).then(function () {
                        UniGo.toast('Copied to clipboard.', 'success', null, 2000);
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = value;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) { /* ignore */ }
                    document.body.removeChild(ta);
                    UniGo.toast('Copied to clipboard.', 'success', null, 2000);
                }
                return;
            }
            if (ev.target.closest('[data-print]')) {
                ev.preventDefault();
                window.print();
            }
        });
    }

    /* ------------------------------------------------------------------
     * Offline banner
     * ---------------------------------------------------------------- */
    function setupConnectivity() {
        function sync() {
            document.body.classList.toggle('is-offline', !navigator.onLine);
        }
        window.addEventListener('online', function () { sync(); UniGo.toast('Back online.', 'success', null, 2500); });
        window.addEventListener('offline', function () { sync(); UniGo.toast('You are offline. Data may be out of date.', 'warning'); });
        sync();
    }

    /* ------------------------------------------------------------------
     * Countdown timers (departures, ETAs, session warnings)
     * ---------------------------------------------------------------- */
    function setupCountdowns() {
        var nodes = UniGo.qsa('[data-countdown]');
        if (!nodes.length) return;

        function tick() {
            nodes.forEach(function (node) {
                var target = new Date(String(node.getAttribute('data-countdown')).replace(' ', 'T'));
                if (isNaN(target.getTime())) return;
                var diff = Math.floor((target.getTime() - Date.now()) / 1000);

                if (diff <= 0) {
                    node.textContent = node.getAttribute('data-countdown-done') || 'Departed';
                    if (node.getAttribute('data-countdown-refresh') === '1' && !node.dataset.refreshed) {
                        node.dataset.refreshed = '1';
                        window.setTimeout(function () { window.location.reload(); }, 30000);
                    }
                    return;
                }
                var h = Math.floor(diff / 3600);
                var m = Math.floor((diff % 3600) / 60);
                var s = diff % 60;
                var pad = function (n) { return n < 10 ? '0' + n : String(n); };
                var text = h > 0 ? h + 'h ' + m + 'm' : (m > 0 ? m + 'm ' + pad(s) + 's' : s + 's');
                node.textContent = text;
                node.classList.toggle('text-danger', diff < 600);
            });
        }
        tick();
        window.setInterval(tick, 1000);
    }

    /* ------------------------------------------------------------------
     * Live regions: poll a JSON endpoint and refresh marked elements
     * ---------------------------------------------------------------- */
    UniGo.poll = function (el, url, options) {
        options = options || {};
        var interval = parseInt(options.interval || el.getAttribute('data-poll-interval') || '15000', 10);
        var renderers = {
            'data-poll-target': function (node, res) { node.innerHTML = (res && res.html) || ''; UniGo.upgradeIcons(node); },
            'data-poll-json': function (node, res) {
                var key = node.getAttribute('data-poll-json');
                var value = key ? (res.data || {})[key] : null;
                node.textContent = value === null || value === undefined ? '-' : value;
            }
        };

        function run() {
            if (document.hidden) return;
            UniGo.api(url, { silent: true }).then(function (res) {
                if (!res || !res.success) return;
                if (options.onData) options.onData(res, el);
                Object.keys(renderers).forEach(function (attr) {
                    var node = el.querySelector('[' + attr + ']') || (el.hasAttribute(attr) ? el : null);
                    if (node) renderers[attr](node, res);
                });
            }).catch(function () { /* transient failure - keep the last render */ });
        }

        if (el.getAttribute('data-poll-now') === '1') run();
        var id = window.setInterval(run, Math.max(5000, interval));
        window.addEventListener('beforeunload', function () { window.clearInterval(id); });
        return { stop: function () { window.clearInterval(id); } };
    };

    function setupPolls() {
        UniGo.qsa('[data-poll-url]').forEach(function (el) {
            UniGo.instances[el.id || ('poll-' + Math.random().toString(36).slice(2))] =
                UniGo.poll(el, el.getAttribute('data-poll-url'));
        });
    }

    /* ------------------------------------------------------------------
     * Unread notification badge
     * ---------------------------------------------------------------- */
    function refreshBadges() {
        var badge = UniGo.qs('[data-unread]');
        if (!badge) return;
        UniGo.api('notifications/unread-count', { silent: true }).then(function (res) {
            var count = (res && res.data && res.data.unread) || 0;
            badge.textContent = count > 9 ? '9+' : count;
            badge.dataset.unread = count;
            badge.hidden = count === 0;
        }).catch(function () { /* ignore */ });
    }
    UniGo.refreshBadges = refreshBadges;

    /* ------------------------------------------------------------------
     * Swappable origin / destination fields
     * ---------------------------------------------------------------- */
    function setupSwap() {
        UniGo.qsa('[data-swap]').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                var group = btn.closest('[data-swap-group]') || document;
                var from = group.querySelector('[name="origin"]');
                var to = group.querySelector('[name="destination"]');
                if (!from || !to) return;
                var tmp = from.value;
                from.value = to.value;
                to.value = tmp;
                from.dispatchEvent(new Event('change', { bubbles: true }));
                to.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    /* ------------------------------------------------------------------
     * Service worker (static assets only, see public/sw.js)
     * ---------------------------------------------------------------- */
    function setupServiceWorker() {
        if (UniGo.config.pwa === false || !('serviceWorker' in navigator)) return;
        var secure = window.isSecureContext ||
            ['localhost', '127.0.0.1', '::1'].indexOf(window.location.hostname) !== -1;
        if (!secure) return; // browsers refuse to register over plain http
        window.addEventListener('load', function () {
            navigator.serviceWorker.register((UniGo.config.baseUrl || '') + 'sw.js')
                .catch(function () { /* offline support is optional */ });
        });
    }

    /* ------------------------------------------------------------------
     * Boot
     * ---------------------------------------------------------------- */
    function boot() {
        UniGo.upgradeIcons(document);
        setupSidebar();
        setupDropdowns();
        setupTabs();
        setupModals();
        setupConfirms();
        setupButtons();
        setupAlerts();
        setupPasswords();
        setupSuggest();
        setupFilters();
        setupMisc();
        setupConnectivity();
        setupCountdowns();
        setupPolls();
        setupSwap();
        setupServiceWorker();
        UniGo.upgradeIcons(document);
    }

    window.UniGo = UniGo;
    document.addEventListener('DOMContentLoaded', boot);
})(window, document);
