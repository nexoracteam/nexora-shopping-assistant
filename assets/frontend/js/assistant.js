/**
 * Nexora Shopping Assistant — customer-facing shopping assistant widget.
 *
 * Built on wp.element (WordPress' bundled React) so no additional framework is
 * shipped to the page. Internal slug, hooks, shortcode, and text domain remain
 * "convocart". All display copy is driven by the store's own settings.
 */
(function (window, document) {
    'use strict';

    var cfg = window.ConvoCartConfig || {};
    var STR = cfg.strings || {};
    var pendingOpen = null;
    var listenersReady = false;

    /** Translatable string lookup with an English fallback. */
    function t(key, fallback) {
        return (STR && typeof STR[key] === 'string' && STR[key]) ? STR[key] : fallback;
    }

    /** Up to two uppercase initials derived from the configured assistant name. */
    function brandInitials() {
        var name = String(cfg.assistantName || (STR && STR.brand) || '').trim();
        if (!name) {
            return 'AI';
        }
        var parts = name.split(/\s+/).filter(Boolean);
        var initials = parts.slice(0, 2).map(function (p) { return p.charAt(0); }).join('');
        return (initials || name.charAt(0) || 'AI').toUpperCase();
    }

    /* ------------------------------------------------------------------ *
     * Trigger delegation — works even before wp.element mounts.
     * ------------------------------------------------------------------ */
    document.body.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) {
            return;
        }
        var btn = e.target.closest('[data-convocart-open], [data-convocart-trigger]');
        if (btn) {
            e.preventDefault();
            if (!window.wp || !window.wp.element) {
                window.alert(t('assistantUnavailable', 'The assistant is loading. If this persists, please clear your browser cache.'));
                return;
            }
            if (!listenersReady) { pendingOpen = { trigger: btn }; }
            else { window.dispatchEvent(new CustomEvent('convocart:open', { detail: { trigger: btn } })); }
        }
    });

    var element = window.wp && window.wp.element;
    if (!element) {
        return;
    }

    var h = element.createElement;
    var Fragment = element.Fragment;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var useRef = element.useRef;
    var useCallback = element.useCallback;
    var createRoot = element.createRoot;
    var render = element.render;

    var restBase = cfg.restBase || '';
    var sessionToken = '';
    var rootInstance = null;
    var isMounted = false;
    var controllers = new Set();
    var requestGeneration = 0;

    /* ------------------------------------------------------------------ *
     * Utilities.
     * ------------------------------------------------------------------ */
    function uid() {
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function isOffline() {
        return typeof navigator !== 'undefined' && navigator.onLine === false;
    }

    /** Fetch wrapper: injects nonce/session/idempotency and parses JSON safely. */
    function request(path, options, refreshed) {
        var config = Object.assign({}, options || {});
        var generation = requestGeneration;
        var controller = new AbortController();
        controllers.add(controller);
        var timer = window.setTimeout(function () { controller.abort(); }, cfg.requestTimeout || 40000);
        config.signal = controller.signal;
        config.cache = 'no-store';
        config.credentials = 'same-origin';
        config.headers = Object.assign({ 'Content-Type': 'application/json' }, config.headers || {});
        if (cfg.nonce) {
            config.headers['X-WP-Nonce'] = cfg.nonce;
        }
        if (sessionToken) {
            config.headers['X-ConvoCart-Session'] = sessionToken;
        }
        return fetch(restBase + path, config).then(function (res) {
            return res.text().then(function (raw) {
                var body = {};
                try {
                    body = raw ? JSON.parse(raw) : {};
                } catch (e) {
                    throw new Error(t('messageFailed', 'Invalid server response. Please retry.'));
                }
                if (generation !== requestGeneration) { throw new DOMException('Request superseded', 'AbortError'); }
                if (!res.ok) {
                    if (!refreshed && res.status === 403 && (body.code === 'rest_cookie_invalid_nonce' || body.code === 'convocart_invalid_nonce')) {
                        return fetch(cfg.nonceUrl || (restBase + '/nonce'), { cache: 'no-store', credentials: 'same-origin', signal: controller.signal }).then(function (nonceResponse) {
                            if (!nonceResponse.ok) { throw new Error('Session renewal failed. Refresh the page.'); }
                            return nonceResponse.json();
                        }).then(function (data) {
                            if (generation !== requestGeneration || !data.nonce) { throw new DOMException('Request superseded', 'AbortError'); }
                            cfg.nonce = data.nonce;
                            return request(path, options, true);
                        });
                    }
                    var err = new Error(body.message || t('messageFailed', 'Request failed.'));
                    err.status = res.status;
                    err.code = body.code || '';
                    err.retryAfter = Number(res.headers.get('Retry-After') || (body.data && body.data.retry_after) || 0);
                    throw err;
                }
                var newToken = res.headers.get('X-ConvoCart-Session');
                if (newToken) {
                    sessionToken = newToken;
                }
                return body;
            });
        }).finally(function () { window.clearTimeout(timer); controllers.delete(controller); });
    }

    function reportEvent(name, objectType, objectId, metadata) {
        if (!cfg.analyticsEnabled) {
            return;
        }
        try {
            request('/events', {
                method: 'POST',
                body: JSON.stringify({
                    event: name,
                    object_type: objectType || '',
                    object_id: objectId || 0,
                    metadata: metadata || {}
                })
            }).catch(function () {});
        } catch (e) { /* analytics is best-effort */ }
    }

    /** Derive a stock badge label + modifier class from a product. */
    function stockInfo(product) {
        var status = product.stock_status || (product.in_stock ? 'instock' : 'outofstock');
        if (status === 'outofstock') {
            return { label: t('outOfStock', 'Out of stock'), cls: 'is-out' };
        }
        if (status === 'onbackorder') {
            return { label: t('onBackorder', 'Available to order'), cls: 'is-backorder' };
        }
        if (typeof product.stock_qty === 'number' && product.stock_qty > 0 && product.stock_qty <= 5) {
            return { label: t('lowStock', 'Low stock'), cls: 'is-low' };
        }
        return { label: t('inStock', 'In stock'), cls: 'is-in' };
    }

    /* ------------------------------------------------------------------ *
     * Presentational components.
     * ------------------------------------------------------------------ */
    function Skeleton(props) {
        var lines = props.lines || 1;
        var items = [];
        for (var i = 0; i < lines; i++) {
            items.push(h('span', { key: i, className: 'convocart-skeleton-line' }));
        }
        return h('div', { className: 'convocart-skeleton ' + (props.variant ? 'convocart-skeleton--' + props.variant : ''), 'aria-hidden': 'true' },
            props.variant === 'product' ? h('span', { className: 'convocart-skeleton-thumb' }) : null,
            h('div', { className: 'convocart-skeleton-lines' }, items)
        );
    }

    function ProductCard(props) {
        var product = props.product;
        var variable = product.type === 'variable' || (product.variation_ids && product.variation_ids.length);
        var stock = stockInfo(product);
        var soldOut = stock.cls === 'is-out';
        var canAdd = props.addToCartEnabled && product.purchasable && !soldOut && product.type === 'simple' && !variable;
        var state = props.cartState || 'idle';
        useEffect(function () { reportEvent('product_impression', 'product', product.id, {}); }, [product.id]);
        function productClick() { reportEvent(variable ? 'select_options' : 'product_click', 'product', product.id, {}); }

        var actionBtn;
        if (variable) {
            actionBtn = h('a', { className: 'convocart-btn convocart-btn-secondary', href: product.permalink, onClick: productClick }, t('options', 'Choose options'));
        } else if (canAdd) {
            actionBtn = h('button', {
                type: 'button',
                className: 'convocart-btn convocart-btn-primary convocart-add' + (state === 'added' ? ' is-added' : ''),
                onClick: function () { props.onAddToCart(product); },
                disabled: state === 'adding' || state === 'added',
                'aria-live': 'polite'
            }, state === 'adding' ? t('adding', 'Adding…') : (state === 'added' ? h(Fragment, null, h('span', { className: 'convocart-check', 'aria-hidden': 'true' }), t('added', 'Added')) : t('addToCart', 'Add to Cart')));
        } else {
            actionBtn = h('a', { className: 'convocart-btn convocart-btn-ghost', href: product.permalink, onClick: productClick }, t('viewProduct', 'View product'));
        }

        return h('article', { className: 'convocart-pcard' + (soldOut ? ' is-sold-out' : '') },
            h('div', { className: 'convocart-pcard-media' },
                h('img', { src: product.image, alt: product.name, className: 'convocart-pcard-img', loading: 'lazy', decoding: 'async', width: '96', height: '96' }),
                h('span', { className: 'convocart-stock-badge ' + stock.cls }, stock.label)
            ),
            h('div', { className: 'convocart-pcard-info' },
                h('h4', { className: 'convocart-pcard-name' }, h('a', { href: product.permalink, onClick: function () { reportEvent('product_click', 'product', product.id, {}); } }, product.name)),
                h('div', { className: 'convocart-pcard-price', dangerouslySetInnerHTML: { __html: product.price_html || product.price_text || product.price } }),
                h('div', { className: 'convocart-pcard-actions' }, actionBtn)
            )
        );
    }

    function BotAvatar() {
        return h('div', { className: 'convocart-avatar', 'aria-hidden': 'true' },
            cfg.botIcon
                ? h('img', { src: cfg.botIcon, alt: '', className: 'convocart-avatar-img', width: '32', height: '32' })
                : h('span', { className: 'convocart-avatar-mark' }, brandInitials())
        );
    }

    function Message(props) {
        var msg = props.msg;
        var isAi = msg.role === 'assistant';
        return h('div', { className: 'convocart-msg-wrap ' + (isAi ? 'convocart-msg-wrap--ai' : 'convocart-msg-wrap--user'), style: { animationDelay: (props.delay || 0) + 'ms' }, ref: props.innerRef || null },
            isAi ? h(BotAvatar, null) : null,
            h('div', { className: 'convocart-msg convocart-msg--' + msg.role },
                h('div', { className: 'convocart-msg__text' }, msg.text)
            )
        );
    }

    function TypingIndicator() {
        return h('div', { className: 'convocart-msg-wrap convocart-msg-wrap--ai' },
            h(BotAvatar, null),
            h('div', { className: 'convocart-msg convocart-msg--assistant' },
                h('div', { className: 'convocart-typing', role: 'status', 'aria-label': t('thinking', 'Thinking…') },
                    h('span', null), h('span', null), h('span', null))
            )
        );
    }

    /* ------------------------------------------------------------------ *
     * Main assistant.
     * ------------------------------------------------------------------ */
    function Assistant() {
        var _o = useState(false), open = _o[0], setOpen = _o[1];
        var _b = useState(null), bootstrap = _b[0], setBootstrap = _b[1];
        var _c = useState(null), conversation = _c[0], setConversation = _c[1];
        var _m = useState([]), messages = _m[0], setMessages = _m[1];
        var _i = useState(''), input = _i[0], setInput = _i[1];
        var _s = useState(''), status = _s[0], setStatus = _s[1];
        var _st = useState('info'), statusTone = _st[0], setStatusTone = _st[1];
        var _l = useState(false), loading = _l[0], setLoading = _l[1];
        var _bl = useState(false), booting = _bl[0], setBooting = _bl[1];
        var _cs = useState({}), cartStates = _cs[0], setCartStates = _cs[1];
        var _cea = useState(false), cartEverAdded = _cea[0], setCartEverAdded = _cea[1];
        var _sg = useState([]), suggestions = _sg[0], setSuggestions = _sg[1];
        var _err = useState(null), fatalError = _err[0], setFatalError = _err[1];
        // Feedback: contextual, thumbs-first. Phases — hidden | prompt | comment | thanks.
        var _fp = useState('hidden'), feedbackPhase = _fp[0], setFeedbackPhase = _fp[1];
        var _fr = useState(''), feedbackRating = _fr[0], setFeedbackRating = _fr[1];
        var _fc = useState(''), feedbackComment = _fc[0], setFeedbackComment = _fc[1];
        var _fs = useState('idle'), feedbackStatus = _fs[0], setFeedbackStatus = _fs[1];
        var _fd = useState(false), feedbackDone = _fd[0], setFeedbackDone = _fd[1];
        var _ar = useState(0), aiReplies = _ar[0], setAiReplies = _ar[1];

        var panelRef = useRef(null);
        var contentRef = useRef(null);
        var lastAiMsgRef = useRef(null);
        var inputRef = useRef(null);
        var lastFocusRef = useRef(null);
        var liveRef = useRef(null);
        var touchStartY = useRef(0);
        var touchDeltaY = useRef(0);
        var bootAttempted = useRef(false);
        var sessionVersion = useRef(0);
        var cartRequests = useRef({});

        function setNotice(text, tone) {
            setStatus(text || '');
            setStatusTone(tone || 'info');
        }

        var openAssistant = useCallback(function (evt) {
            // Prefer the explicit trigger element (WebKit does not focus buttons on click).
            var trigger = evt && evt.detail && evt.detail.trigger;
            lastFocusRef.current = trigger || document.activeElement;
            document.body.classList.add('convocart-open');
            setOpen(true);
        }, []);

        var closeAssistant = useCallback(function () {
            document.body.classList.remove('convocart-open');
            setOpen(false);
        }, []);

        /* Intercepted close. Priority order:
           1. Feedback already given → clear the session so a reopen starts fresh.
           2. Leaving mid-conversation without feedback → surface the prompt once.
           3. Otherwise close normally. */
        var requestClose = useCallback(function () {
            if (feedbackDone) {
                resetSession();
                closeAssistant();
                return;
            }
            if (conversation && aiReplies >= 1 && feedbackPhase === 'hidden') {
                setFeedbackPhase('prompt');
                return;
            }
            closeAssistant();
        }, [conversation, feedbackDone, aiReplies, feedbackPhase, closeAssistant]);

        /* Restore focus to the launcher after the dialog has unmounted (WebKit
           rejects focus() while the modal overlay is still on screen). */
        var wasOpenRef = useRef(false);
        useEffect(function () {
            if (wasOpenRef.current && !open) {
                var target = lastFocusRef.current;
                if (target && typeof target.focus === 'function') {
                    // Defer past the modal teardown so WebKit accepts the focus.
                    var raf = (typeof window !== 'undefined' && window.requestAnimationFrame)
                        ? window.requestAnimationFrame
                        : function (cb) { return setTimeout(cb, 16); };
                    raf(function () {
                        raf(function () {
                            if (typeof target.focus === 'function') {
                                target.focus();
                            }
                        });
                    });
                }
            }
            wasOpenRef.current = open;
        }, [open]);

        /* Open/close event wiring. */
        useEffect(function () {
            window.addEventListener('convocart:open', openAssistant);
            window.addEventListener('convocart:close', closeAssistant);
            function restartEvent() { resetSession(); openAssistant(); }
            window.addEventListener('convocart:restart', restartEvent);
            listenersReady = true;
            if (pendingOpen) {
                var pending = pendingOpen;
                pendingOpen = null;
                if (pending.restart) { resetSession(); }
                openAssistant({ detail: pending });
            }
            return function () {
                listenersReady = false;
                window.removeEventListener('convocart:open', openAssistant);
                window.removeEventListener('convocart:close', closeAssistant);
                window.removeEventListener('convocart:restart', restartEvent);
            };
        }, [openAssistant, closeAssistant]);

        /* Hash trigger: #nexora-shopping-assistant (and the legacy #convocart) open the popup.
           Use these as href in Elementor buttons to trigger the assistant. */
        useEffect(function () {
            if (!cfg.hashTriggerEnabled) { return; }
            var HASHES = ['#nexora-shopping-assistant', '#convocart'];
            function checkHash() {
                if (HASHES.indexOf(window.location.hash.toLowerCase()) !== -1) {
                    openAssistant();
                    if (history.replaceState) {
                        history.replaceState(null, '', window.location.pathname + window.location.search);
                    }
                }
            }
            checkHash();
            window.addEventListener('hashchange', checkHash);
            function handleLink(e) {
                var link = e.target.closest ? e.target.closest('a[href]') : null;
                if (!link) return;
                var href = (link.getAttribute('href') || '').toLowerCase();
                if (HASHES.indexOf(href) !== -1) {
                    e.preventDefault();
                    openAssistant();
                }
            }
            document.addEventListener('click', handleLink);
            return function () {
                window.removeEventListener('hashchange', checkHash);
                document.removeEventListener('click', handleLink);
            };
        }, [openAssistant]);

        /* Body scroll lock while open. */
        useEffect(function () {
            if (!open) {
                return;
            }
            var prev = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            return function () { document.body.style.overflow = prev; };
        }, [open]);

        /* Bootstrap + start conversation on first open. */
        useEffect(function () {
            if (open && !bootstrap && !booting && !fatalError && !bootAttempted.current) {
                bootAttempted.current = true;
                var version = sessionVersion.current;
                setBooting(true);
                setFatalError(null);
                request('/bootstrap').then(function (data) {
                    if (version !== sessionVersion.current) { throw new DOMException('Request superseded', 'AbortError'); }
                    if (!data.assistant || !data.assistant.enabled) { throw new Error(t('assistantUnavailable', 'The assistant is unavailable.')); }
                    sessionToken = data.session || '';
                    cfg.nonce = data.nonce || cfg.nonce;
                    setBootstrap(data);
                    if (data.suggestions && data.suggestions.length) {
                        setSuggestions(data.suggestions);
                    }
                    return request('/conversation/start', { method: 'POST', body: '{}' });
                }).then(function (data) {
                    if (version !== sessionVersion.current) { return; }
                    setConversation(data);
                    var welcome = (data && data.message) || (bootstrap && bootstrap.assistant && bootstrap.assistant.welcome) || t('welcomeFallback', "Hi! How can I help you today?");
                    setMessages([{ id: uid(), role: 'assistant', text: welcome }]);
                    setBooting(false);
                }).catch(function (e) {
                    if (version !== sessionVersion.current) { return; }
                    setBooting(false);
                    setFatalError(isOffline() ? t('offline', 'You appear to be offline.') : (e.message || t('startFailed', 'The assistant could not start. Please try again.')));
                });
            }
        }, [open, bootstrap, booting, fatalError]);

        /* Focus management: move focus into the panel on open. */
        useEffect(function () {
            if (open && panelRef.current) {
                var thanks = panelRef.current.querySelector('.convocart-thanks-overlay');
                var target = (thanks && thanks.querySelector('button')) || inputRef.current || panelRef.current;
                window.setTimeout(function () {
                    if (target && typeof target.focus === 'function') {
                        target.focus();
                    }
                }, 60);
            }
        }, [open, booting, feedbackPhase]);

        /* Keyboard: Esc to close, Tab focus trap. */
        useEffect(function () {
            if (!open) {
                return;
            }
            function onKey(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeAssistant();
                    return;
                }
                if (e.key === 'Tab' && panelRef.current) {
                    var focusScope = panelRef.current.querySelector('.convocart-thanks-overlay') || panelRef.current;
                    var nodes = focusScope.querySelectorAll('a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])');
                    var focusable = [];
                    for (var i = 0; i < nodes.length; i++) {
                        var node = nodes[i];
                        // Skip disabled or aria-hidden controls (e.g. the composer while booting).
                        if (node.disabled || node.getAttribute('aria-hidden') === 'true') {
                            continue;
                        }
                        // Skip elements that are not actually focusable (hidden error/offline/skeleton states).
                        if (node.offsetWidth > 0 || node.offsetHeight > 0 || node.getClientRects().length > 0) {
                            focusable.push(node);
                        }
                    }
                    if (!focusable.length) {
                        e.preventDefault();
                        panelRef.current.focus();
                        return;
                    }
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    var active = document.activeElement;
                    // Focus already escaped the panel (or rests on the panel container): pull it back in.
                    if (!focusScope.contains(active) || active === focusScope) {
                        e.preventDefault();
                        (e.shiftKey ? last : first).focus();
                    } else if (e.shiftKey && active === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && active === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }
            document.addEventListener('keydown', onKey);
            return function () { document.removeEventListener('keydown', onKey); };
        }, [open, closeAssistant]);

        /* Announce the latest assistant message to screen readers. */
        useEffect(function () {
            if (!liveRef.current) {
                return;
            }
            var last = messages[messages.length - 1];
            if (last && last.role === 'assistant') {
                liveRef.current.textContent = last.text;
            }
        }, [messages]);

        /* Auto-scroll behaviour.
         * When a new assistant reply arrives, bring the START of that reply to the
         * top of the scroll area so the customer reads the answer first; the product
         * recommendations then sit naturally below it. For everything else (the
         * customer's own message, the typing indicator), keep following the bottom. */
        useEffect(function () {
            var container = contentRef.current;
            if (!container) {
                return;
            }
            var behavior = prefersReducedMotion() ? 'auto' : 'smooth';
            var last = messages[messages.length - 1];
            if (!loading && last && last.role === 'assistant' && lastAiMsgRef.current) {
                // Align the assistant reply near the top, leaving a little breathing room.
                // Measured against the container so it is independent of CSS positioning.
                var cRect = container.getBoundingClientRect();
                var mRect = lastAiMsgRef.current.getBoundingClientRect();
                var top = Math.max(0, container.scrollTop + (mRect.top - cRect.top) - 12);
                container.scrollTo({ top: top, behavior: behavior });
            } else {
                container.scrollTo({ top: container.scrollHeight, behavior: behavior });
            }
        }, [messages, loading]);

        function send(e, overrideText) {
            if (e && e.preventDefault) {
                e.preventDefault();
            }
            var txt = (overrideText || input).trim();
            if (!txt || loading || !conversation) {
                return;
            }
            if (isOffline()) {
                setNotice(t('offline', 'You appear to be offline. Check your connection and try again.'), 'error');
                return;
            }
            var limit = (bootstrap && bootstrap.max_message_length) || 2000;
            if (new TextEncoder().encode(txt).length > limit) { setNotice('Please enter a shorter message (maximum ' + limit + ' UTF-8 bytes).', 'error'); return; }
            var version = sessionVersion.current;
            setMessages(function (prev) { return prev.concat({ id: uid(), role: 'user', text: txt }); });
            setInput('');
            setLoading(true);
            setNotice('');
            setSuggestions([]);

            request('/conversation/' + conversation.uuid + '/message', {
                method: 'POST',
                body: JSON.stringify({ message: txt })
            }).then(function (data) {
                if (version !== sessionVersion.current) { return; }
                var merged = Object.assign({}, conversation, data);
                if (data.state === 'chatting') {
                    merged.products = [];
                }
                setConversation(merged);
                var reply = data.message || t('replyFallback', 'Please tell me which products you are looking for.');
                setMessages(function (prev) { return prev.concat({ id: uid(), role: 'assistant', text: reply }); });
                // Contextual feedback: surface the prompt once, after the 2nd assistant reply.
                setAiReplies(function (n) {
                    var next = n + 1;
                    if (next >= 2 && !feedbackDone) {
                        setFeedbackPhase(function (p) { return p === 'hidden' ? 'prompt' : p; });
                    }
                    return next;
                });
                var count = (data.products ? data.products.length : 0);
                if (count === 0 && data.safety) {
                    setNotice(data.safety, 'info');
                } else if (count === 0 && !data.message) {
                    setNotice(t('emptyResults', 'I could not find a match for that. Try rephrasing or ask about another dish.'), 'info');
                }
                if (data.suggestions && data.suggestions.length) {
                    setSuggestions(data.suggestions);
                }
            }).catch(function (err) {
                if (version !== sessionVersion.current) { return; }
                if (err.code === 'convocart_invalid_session') { setFatalError(t('sessionExpired', 'Session expired. Start a new chat.')); }
                setMessages(function (prev) { return prev.concat({ id: uid(), role: 'assistant', text: t('messageFailed', 'The assistant could not respond. Please try again.') }); });
                setNotice(isOffline() ? t('offline', 'You appear to be offline.') : (err.message || t('messageFailed', 'The assistant could not respond.')), 'error');
            }).finally(function () {
                if (version === sessionVersion.current) { setLoading(false); }
            });
        }

        function onAddToCart(product) {
            if (cartRequests.current[product.id] && cartRequests.current[product.id].busy) { return; }
            var version = sessionVersion.current;
            var entry = cartRequests.current[product.id] || { key: uid() };
            entry.busy = true;
            cartRequests.current[product.id] = entry;
            var prevState = cartStates[product.id];
            // Optimistic: show success immediately.
            setCartStates(function (s) { var n = Object.assign({}, s); n[product.id] = 'adding'; return n; });

            request('/cart/add', {
                method: 'POST',
                headers: { 'X-ConvoCart-Idempotency-Key': entry.key },
                body: JSON.stringify({ product_id: product.id, quantity: 1 })
            }).then(function (data) {
                if (version !== sessionVersion.current) { return; }
                delete cartRequests.current[product.id];
                setCartStates(function (s) { var n = Object.assign({}, s); n[product.id] = 'added'; return n; });
                setCartEverAdded(true);
                setNotice(t('addedToCart', 'Added to your basket.'), 'success');
                if (data && data.replayed && window.jQuery) {
                    window.jQuery(document.body).trigger('wc_fragment_refresh');
                } else if (data && data.fragments && window.jQuery) {
                    try {
                        Object.keys(data.fragments).forEach(function (selector) { window.jQuery(selector).replaceWith(data.fragments[selector]); });
                        window.jQuery(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, window.jQuery()]);
                    } catch (e) { /* fragments optional */ }
                }
                document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true, detail: { preserveCartData: false } }));
                window.setTimeout(function () {
                    if (version !== sessionVersion.current) { return; }
                    setCartStates(function (s) {
                        if (s[product.id] !== 'added') { return s; }
                        var n = Object.assign({}, s); n[product.id] = 'idle'; return n;
                    });
                }, 2400);
            }).catch(function (err) {
                if (version !== sessionVersion.current) { return; }
                entry.busy = false;
                // A definitive rejection made no cart change. A later attempt
                // may have different stock or required options; give it a new key.
                if (err.code === 'convocart_cart_rejected') { delete cartRequests.current[product.id]; }
                // Rollback to the prior state on failure.
                setCartStates(function (s) { var n = Object.assign({}, s); n[product.id] = prevState || 'idle'; return n; });
                setNotice(err.message || t('cartFailed', 'Could not add to basket.'), 'error');
                reportEvent('add_to_cart_failure', 'product', product.id, { error_code: err.code || '' });
            });
        }

        /* Tear the conversation down to a pristine state. Used both by
           "Start a new chat" and by closing after feedback so a reopen boots fresh. */
        function resetSession() {
            sessionVersion.current += 1;
            requestGeneration += 1;
            controllers.forEach(function (controller) { controller.abort(); });
            controllers.clear();
            sessionToken = '';
            bootAttempted.current = false;
            cartRequests.current = {};
            setFatalError(null);
            setLoading(false);
            setInput('');
            setConversation(null);
            setMessages([]);
            setCartStates({});
            setCartEverAdded(false);
            setNotice('');
            setBootstrap(null);
            setSuggestions([]);
            setFeedbackPhase('hidden');
            setFeedbackRating('');
            setFeedbackComment('');
            setFeedbackStatus('idle');
            setFeedbackDone(false);
            setAiReplies(0);
            // Re-run bootstrap effect.
            setBooting(false);
        }

        function restart() {
            resetSession();
        }

        /* Contextual feedback. The backend automatically captures the last
           question/answer pair; we send the rating + optional comment.
           Thumbs-first: 👍 submits instantly, 👎 reveals a comment box. */
        function sendFeedback(rating, comment) {
            if (!conversation) {
                return;
            }
            setFeedbackStatus('sending');
            var version = sessionVersion.current;
            request('/conversation/' + conversation.uuid + '/feedback', {
                method: 'POST',
                body: JSON.stringify({ feedback: rating, comment: (comment || '').slice(0, 2000) })
            }).then(function () {
                if (version !== sessionVersion.current) { return; }
                setFeedbackStatus('idle');
                setFeedbackDone(true);
                setFeedbackPhase('thanks');
            }).catch(function () {
                if (version !== sessionVersion.current) { return; }
                setFeedbackStatus('error');
            });
        }

        function chooseRating(rating) {
            if (feedbackStatus === 'sending') {
                return;
            }
            setFeedbackRating(rating);
            if (rating === 'helpful') {
                // Positive: no need to ask for more — thank them straight away.
                sendFeedback('helpful', '');
            } else {
                // Negative: invite a short comment before submitting.
                setFeedbackStatus('idle');
                setFeedbackPhase('comment');
            }
        }

        function submitComment(e) {
            if (e && e.preventDefault) {
                e.preventDefault();
            }
            if (feedbackStatus === 'sending') {
                return;
            }
            sendFeedback('not_helpful', feedbackComment);
        }

        function dismissFeedback() {
            // Treat a dismiss as "answered" so it does not re-appear this session.
            setFeedbackDone(true);
            setFeedbackPhase('hidden');
        }

        /* Swipe-to-dismiss on the mobile sheet grabber. */
        function onTouchStart(e) {
            touchStartY.current = e.touches[0].clientY;
            touchDeltaY.current = 0;
        }
        function onTouchMove(e) {
            touchDeltaY.current = e.touches[0].clientY - touchStartY.current;
            if (touchDeltaY.current > 0 && panelRef.current) {
                panelRef.current.style.transform = 'translateY(' + touchDeltaY.current + 'px)';
            }
        }
        function onTouchEnd() {
            if (panelRef.current) {
                panelRef.current.style.transform = '';
            }
            if (touchDeltaY.current > 120) {
                requestClose();
            }
        }

        if (!open) {
            return null;
        }

        var products = conversation && conversation.products ? conversation.products : [];
        var brand = (bootstrap && bootstrap.assistant && bootstrap.assistant.name) || t('brand', 'Shopping Assistant');
        var addEnabled = !bootstrap || !bootstrap.features || bootstrap.features.add_to_cart !== false;

        return h('div', { className: 'convocart-overlay', onClick: function (e) { if (e.target === e.currentTarget) { requestClose(); } } },
            h('div', {
                className: 'convocart-panel',
                ref: panelRef,
                role: 'dialog',
                'aria-modal': 'true',
                'aria-label': brand,
                tabIndex: -1
            },
                h('button', {
                    className: 'convocart-grabber',
                    type: 'button',
                    'aria-label': t('close', 'Close assistant'),
                    onClick: requestClose,
                    onTouchStart: onTouchStart,
                    onTouchMove: onTouchMove,
                    onTouchEnd: onTouchEnd
                }),
                h('header', { className: 'convocart-header' },
                    h('div', { className: 'convocart-header-title' },
                        h('span', { className: 'convocart-brandmark', 'aria-hidden': 'true' },
                            cfg.botIcon ? h('img', { src: cfg.botIcon, alt: '', className: 'convocart-brandmark-img', width: '36', height: '36' }) : brandInitials()
                        ),
                        h('span', { className: 'convocart-brandtext' },
                            h('strong', null, brand),
                            h('span', { className: 'convocart-presence' },
                                h('span', { className: 'convocart-dot', 'aria-hidden': 'true' }),
                                t('online', 'Online')
                            )
                        )
                    ),
                    h('div', { className: 'convocart-header-actions' },
                        conversation ? h('button', { className: 'convocart-icon-btn', type: 'button', onClick: restart, 'aria-label': t('restart', 'Start over') },
                            h('svg', { width: '18', height: '18', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true' },
                                h('polyline', { points: '23 4 23 10 17 10' }),
                                h('path', { d: 'M20.49 15a9 9 0 1 1-2.12-9.36L23 10' }))
                        ) : null,
                        h('button', { className: 'convocart-icon-btn convocart-close-btn', type: 'button', onClick: requestClose, 'aria-label': t('close', 'Close assistant') },
                            h('svg', { width: '18', height: '18', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true' },
                                h('line', { x1: '18', y1: '6', x2: '6', y2: '18' }),
                                h('line', { x1: '6', y1: '6', x2: '18', y2: '18' })))
                    )
                ),
                h('span', { className: 'convocart-sr-only', role: 'status', 'aria-live': 'polite', ref: liveRef }),
                feedbackPhase === 'thanks' ? (function () {
                    var chefSrc = cfg.chefAvatar || cfg.brandLogo || cfg.botIcon;
                    return h('div', { className: 'convocart-thanks-overlay', role: 'group', 'aria-label': t('feedbackThanksTitle', 'Thank you!') },
                        h('div', { className: 'convocart-thanks-card' },
                            h('button', { type: 'button', className: 'convocart-thanks-close', 'aria-label': t('feedbackContinue', 'Continue chatting'), onClick: function () { setFeedbackPhase('hidden'); } }, '×'),
                            h('div', { className: 'convocart-thanks-hero' },
                                h('div', { className: 'convocart-thanks-art', 'aria-hidden': 'true' },
                                    chefSrc ? h('img', { src: chefSrc, alt: '', className: 'convocart-thanks-img', width: '120', height: '120' }) : h('span', { className: 'convocart-thanks-emoji' }, '💬')
                                ),
                                h('div', { className: 'convocart-thanks-heading' },
                                    h('span', { className: 'convocart-thanks-eyebrow' }, t('feedbackChef', 'Shopping Assistant') + ' ' + t('feedbackSays', 'says…')),
                                    h('h3', { className: 'convocart-thanks-title' }, t('feedbackThanksTitle', 'Thank you!'), ' ', h('span', { className: 'convocart-thanks-heart', 'aria-hidden': 'true' }, '♥')),
                                    h('p', { className: 'convocart-thanks-body' }, t('feedbackThanksBody', 'Your feedback helps us improve.'))
                                )
                            ),
                            h('div', { className: 'convocart-thanks-received' },
                                h('span', { className: 'convocart-thanks-check', 'aria-hidden': 'true' },
                                    h('svg', { width: '15', height: '15', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '3', strokeLinecap: 'round', strokeLinejoin: 'round' }, h('polyline', { points: '20 6 9 17 4 12' }))
                                ),
                                t('feedbackReceived', 'Your feedback has been received.')
                            ),
                            h('div', { className: 'convocart-thanks-actions' },
                                h('button', { type: 'button', className: 'convocart-btn convocart-btn-primary convocart-thanks-continue', onClick: function () { setFeedbackPhase('hidden'); } }, t('feedbackContinue', 'Continue chatting')),
                                h('button', { type: 'button', className: 'convocart-btn convocart-btn-secondary convocart-thanks-new', onClick: restart }, t('feedbackStartNew', 'Start a new chat'))
                            )
                        )
                    );
                })() : null,
                h('div', { className: 'convocart-body', ref: contentRef },
                    fatalError ? h('div', { className: 'convocart-empty' },
                        h('div', { className: 'convocart-empty-ico', 'aria-hidden': 'true' }, '⚠'),
                        h('p', null, fatalError),
                        h('button', { className: 'convocart-btn convocart-btn-primary', type: 'button', onClick: restart }, t('retry', 'Try again'))
                    ) : null,
                    booting ? h(Fragment, null,
                        h(Skeleton, { variant: 'message', lines: 2 }),
                        h(Skeleton, { variant: 'product' }),
                        h(Skeleton, { variant: 'product' })
                    ) : null,
                    !fatalError ? (function () {
                        // Index of the most recent assistant message — we anchor the
                        // scroll to it so the reply is read before the products below.
                        var lastAiIndex = -1;
                        for (var mi = messages.length - 1; mi >= 0; mi--) {
                            if (messages[mi].role === 'assistant') { lastAiIndex = mi; break; }
                        }
                        return messages.map(function (m, i) {
                            return h(Message, {
                                key: m.id,
                                msg: m,
                                delay: Math.min(i, 4) * 40,
                                innerRef: i === lastAiIndex ? lastAiMsgRef : null
                            });
                        });
                    })() : null,
                    products.length > 0 ? h('div', { className: 'convocart-product-grid' }, products.map(function (p) {
                        return h(ProductCard, { key: p.id, product: p, addToCartEnabled: addEnabled, onAddToCart: onAddToCart, cartState: cartStates[p.id] || 'idle' });
                    })) : null,
                    status ? h('div', { className: 'convocart-status convocart-status--' + statusTone, role: 'status' }, status) : null,
                    loading ? h(TypingIndicator, null) : null
                ),
                (function () {
                    var hasItems = cartEverAdded || Object.keys(cartStates).some(function (k) { return cartStates[k] === 'added'; });
                    var checkoutUrl = bootstrap && bootstrap.urls && bootstrap.urls.checkout;
                    var cartUrl = bootstrap && bootstrap.urls && bootstrap.urls.cart;
                    if (!hasItems || (!checkoutUrl && !cartUrl)) return null;
                    return h('div', { className: 'convocart-cart-bar' },
                        cartUrl ? h('a', { href: cartUrl, className: 'convocart-btn convocart-btn-ghost convocart-cart-link', target: '_blank', rel: 'noopener' },
                            h('svg', { width: '16', height: '16', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2', 'aria-hidden': 'true' },
                                h('circle', { cx: '9', cy: '21', r: '1' }),
                                h('circle', { cx: '20', cy: '21', r: '1' }),
                                h('path', { d: 'M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6' })
                            ),
                            ' ' + t('viewCart', 'View Cart')
                        ) : null,
                        checkoutUrl ? h('a', { href: checkoutUrl, className: 'convocart-btn convocart-btn-primary convocart-checkout-link', target: '_blank', rel: 'noopener' },
                            t('checkout', 'Checkout')
                        ) : null
                    );
                })(),
                (suggestions.length > 0 && !fatalError) ? h('div', { className: 'convocart-suggestions-wrap', 'aria-label': t('suggestionsTitle', 'Try asking') },
                    suggestions.map(function (s, i) {
                        var label = s.text || s;
                        return h('button', { key: i, type: 'button', className: 'convocart-suggestion-pill', onClick: function () { send(null, label); } }, label);
                    })
                ) : null,
                (conversation && !fatalError && (feedbackPhase === 'prompt' || feedbackPhase === 'comment')) ? (function () {
                    var chefSrc = cfg.chefAvatar || cfg.brandLogo || cfg.botIcon;
                    return h('div', { className: 'convocart-fb', role: 'group', 'aria-label': t('feedbackPrompt', 'Was this helpful?') },
                        h('div', { className: 'convocart-fb-chef', 'aria-hidden': 'true' },
                            chefSrc ? h('img', { src: chefSrc, alt: '', className: 'convocart-fb-chef-img', width: '40', height: '40' }) : h('span', null, '💬')
                        ),
                        h('div', { className: 'convocart-fb-body' },
                            h('button', { type: 'button', className: 'convocart-fb-dismiss', 'aria-label': t('feedbackSkip', 'Maybe later'), onClick: dismissFeedback }, '×'),
                            h('p', { className: 'convocart-fb-intro' }, t('feedbackContext', 'Submitting feedback shares your latest question, the reply and optional comment with this store.')),
                            feedbackStatus === 'error' ? h('p', { role: 'alert' }, t('feedbackFailed', 'Feedback could not be saved.')) : null,
                            feedbackPhase === 'prompt' ? h(Fragment, null,
                                h('p', { className: 'convocart-fb-q' }, t('feedbackPrompt', 'Was this helpful?')),
                                h('div', { className: 'convocart-fb-rate' },
                                    h('button', {
                                        type: 'button',
                                        className: 'convocart-fb-btn convocart-fb-btn--up',
                                        disabled: feedbackStatus === 'sending',
                                        onClick: function () { chooseRating('helpful'); }
                                    }, '👍 ' + t('helpful', 'Helpful')),
                                    h('button', {
                                        type: 'button',
                                        className: 'convocart-fb-btn convocart-fb-btn--down',
                                        disabled: feedbackStatus === 'sending',
                                        onClick: function () { chooseRating('not_helpful'); }
                                    }, '👎 ' + t('notHelpful', 'Not helpful'))
                                )
                            ) : null,
                            feedbackPhase === 'comment' ? h('form', { className: 'convocart-fb-form', onSubmit: submitComment },
                                h('p', { className: 'convocart-fb-q' }, t('feedbackCommentPrompt', 'Sorry about that — what could have been better?')),
                                h('label', { className: 'convocart-sr-only', htmlFor: 'convocart-fb-comment' }, t('feedbackComment', 'Comments or suggestions')),
                                h('textarea', {
                                    id: 'convocart-fb-comment',
                                    className: 'convocart-fb-comment',
                                    rows: '2',
                                    maxLength: 2000,
                                    autoFocus: true,
                                    value: feedbackComment,
                                    placeholder: t('feedbackPlaceholder', 'Comments or suggestions (optional)…'),
                                    onChange: function (e) { setFeedbackComment(e.target.value); }
                                }),
                                feedbackStatus === 'error' ? h('p', { className: 'convocart-fb-hint is-error' }, t('feedbackFailed', 'Feedback could not be saved.')) : null,
                                h('div', { className: 'convocart-fb-actions' },
                                    h('button', {
                                        type: 'submit',
                                        className: 'convocart-btn convocart-btn-primary convocart-fb-send',
                                        disabled: feedbackStatus === 'sending'
                                    }, feedbackStatus === 'sending' ? t('feedbackSending', 'Sending…') : t('feedbackSubmit', 'Send feedback'))
                                )
                            ) : null
                        )
                    );
                })() : null,
                h('p', { className: 'convocart-privacy-notice' }, (bootstrap && bootstrap.privacy_notice) || t('privacyNotice', 'Messages are processed by the store’s configured AI providers.')),
                h('form', { className: 'convocart-form', onSubmit: function (e) { send(e); } },
                    h('label', { className: 'convocart-sr-only', htmlFor: 'convocart-input' }, t('typeMessage', 'Type your message')),
                    h('input', {
                        id: 'convocart-input',
                        type: 'text',
                        ref: inputRef,
                        value: input,
                        onChange: function (e) { setInput(e.target.value); },
                        placeholder: t('placeholder', 'Ask me anything about our products…'),
                        disabled: loading || booting || !!fatalError || feedbackPhase === 'thanks',
                        autoComplete: 'off'
                    }),
                    h('button', {
                        className: 'convocart-send',
                        type: 'submit',
                        disabled: loading || booting || !input.trim(),
                        'aria-label': t('send', 'Send')
                    }, h('svg', { width: '20', height: '20', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true' },
                        h('line', { x1: '22', y1: '2', x2: '11', y2: '13' }),
                        h('polygon', { points: '22 2 15 22 11 13 2 9 22 2' })))
                )
            )
        );
    }

    /* ------------------------------------------------------------------ *
     * Mount (lazy — after DOM ready / idle).
     * ------------------------------------------------------------------ */
    function init() {
        if (isMounted) {
            return;
        }
        isMounted = true;

        var root = document.getElementById('convocart-portal') || document.createElement('div');
        root.id = 'convocart-portal';
        root.className = 'convocart-portal';
        document.body.appendChild(root);

        try {
            if (createRoot) {
                rootInstance = createRoot(root);
                rootInstance.render(h(Assistant));
            } else if (render) {
                render(h(Assistant), root);
            }
        } catch (e) {
            if (window.console) {
                window.console.error('Failed to mount Nexora Shopping Assistant UI:', e);
            }
        }

        window.ConvoCart = {
            open: function () { if (!listenersReady) { pendingOpen = {}; } else { window.dispatchEvent(new CustomEvent('convocart:open')); } },
            close: function () { window.dispatchEvent(new CustomEvent('convocart:close')); },
            restart: function () { if (!listenersReady) { pendingOpen = { restart: true }; } else { window.dispatchEvent(new CustomEvent('convocart:restart')); } }
        };
        // Preferred name; window.ConvoCart remains for existing integrations.
        window.NexoraShoppingAssistant = window.ConvoCart;
    }

    function lazyInit() {
        if (window.requestIdleCallback) {
            window.requestIdleCallback(init, { timeout: 2000 });
        } else {
            window.setTimeout(init, 1);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', lazyInit);
    } else {
        lazyInit();
    }
})(window, document);
