(() => {
    const wireAttributePattern = /^wire:/;

    /** Directives réservées (pas des événements DOM). */
    const RESERVED = new Set([
        'model',
        'loading',
        'target',
        'init',
        'poll',
        'dirty',
        'key',
        'ignore',
        'cloak',
        'offline',
        'confirm',
        'error',
        'navigate',
    ]);

    function wireDirectiveName(attributeName) {
        return attributeName.slice('wire:'.length);
    }

    function escapeJsString(value) {
        return value.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    function parseActionExpression(expression) {
        const trimmed = expression.trim();

        if (trimmed === '$refresh') {
            return { type: 'refresh' };
        }

        const match = trimmed.match(/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\((.*)\))?$/s);
        if (!match) {
            throw new Error(`Invalid CakeWire action expression "${expression}".`);
        }

        const action = match[1];
        if (match[2] === undefined) {
            return { type: 'action', action, params: [] };
        }

        return { type: 'action', action, params: parseArguments(match[2]) };
    }

    function parseArguments(raw) {
        const input = raw.trim();
        if (input === '') {
            return [];
        }

        const parts = [];
        let current = '';
        let depth = 0;
        let inString = false;
        let delimiter = '';

        for (let index = 0; index < input.length; index += 1) {
            const character = input[index];
            if (inString) {
                current += character;
                if (character === delimiter && input[index - 1] !== '\\') {
                    inString = false;
                }
                continue;
            }

            if (character === '\'' || character === '"') {
                inString = true;
                delimiter = character;
                current += character;
                continue;
            }

            if (character === '(' || character === '[') {
                depth += 1;
                current += character;
                continue;
            }

            if (character === ')' || character === ']') {
                depth -= 1;
                current += character;
                continue;
            }

            if (character === ',' && depth === 0) {
                parts.push(current.trim());
                current = '';
                continue;
            }

            current += character;
        }

        if (current.trim() !== '') {
            parts.push(current.trim());
        }

        return parts;
    }

    function compileActionCall(expression) {
        const parsed = parseActionExpression(expression);

        if (parsed.type === 'refresh') {
            return 'refresh()';
        }

        const args = parsed.params.length === 0
            ? `'${parsed.action}'`
            : `'${parsed.action}', ${parsed.params.join(', ')}`;

        return `runAction(${args})`;
    }

    function compileDispatchExpression(expression) {
        const trimmed = expression.trim();
        const toMatch = trimmed.match(/->to\(\s*['"]([^'"]+)['"]\s*\)\s*$/);
        if (!toMatch) {
            return trimmed;
        }

        const dispatchCall = trimmed.slice(0, trimmed.indexOf('->to(')).trim();
        const argsMatch = dispatchCall.match(/^\$dispatch\(([\s\S]*)\)$/);
        if (!argsMatch) {
            return trimmed;
        }

        const args = argsMatch[1].trim();
        const eventMatch = args.match(/^(['"])([^'"]+)\1/);
        if (!eventMatch) {
            return trimmed;
        }

        const eventName = eventMatch[2];
        const detail = args.includes(',') ? args.slice(args.indexOf(',') + 1).trim() : '{}';

        return `CakeWire.dispatchTo(${JSON.stringify(eventName)}, ${detail}, ${JSON.stringify(toMatch[1])})`;
    }

    function compileActionHandler(expression, confirmMessage) {
        const trimmed = expression.trim();
        let handler;

        if (trimmed.startsWith('$dispatch(')) {
            handler = compileDispatchExpression(trimmed);
        } else {
            handler = `${compileActionCall(expression)}.catch(error => alert(error.message))`;
        }

        if (confirmMessage) {
            handler = `if(!confirm('${escapeJsString(confirmMessage)}')) return; ${handler}`;
        }

        return handler;
    }

    function parsePollDirective(directive) {
        const match = directive.match(/^poll(?:\.(\d+[smhd]))?$/);
        if (!match) {
            return null;
        }

        const token = match[1] ?? '2s';
        const unit = token.slice(-1);
        const amount = Number.parseInt(token.slice(0, -1), 10);
        const multipliers = { s: 1000, m: 60000, h: 3600000, d: 86400000 };
        const multiplier = multipliers[unit] ?? 1000;

        return amount * multiplier;
    }

    function parseModelModifiers(directive) {
        const segments = directive.split('.');
        const modifiers = segments.slice(1);

        return {
            mode: modifiers.includes('live')
                ? 'live'
                : modifiers.includes('blur')
                    ? 'blur'
                    : 'defer',
            number: modifiers.includes('number'),
            boolean: modifiers.includes('boolean'),
            debounceMs: parseTimingModifier(modifiers, 'debounce', 150),
            throttleMs: parseTimingModifier(modifiers, 'throttle', 150),
        };
    }

    function parseTimingModifier(modifiers, name, fallback) {
        const prefix = `${name}.`;
        const entry = modifiers.find((modifier) => modifier.startsWith(prefix));
        if (!entry) {
            return fallback;
        }

        const value = Number.parseInt(entry.slice(prefix.length), 10);

        return Number.isFinite(value) ? value : fallback;
    }

    function compileModel(attributeName, property, element) {
        const directive = wireDirectiveName(attributeName);
        const options = parseModelModifiers(directive);

        if (element instanceof HTMLInputElement && element.type === 'file') {
            return {
                'x-on:change': `upload('${escapeJsString(property)}', $event)`,
            };
        }

        const modelAttribute = options.number
            ? 'x-model.number'
            : options.boolean
                ? 'x-model.boolean'
                : 'x-model';

        const attributes = {
            [modelAttribute]: `state.${property}`,
        };

        const syncCall = `sync('${escapeJsString(property)}')`;

        if (options.mode === 'live') {
            const usesChangeEvent = element instanceof HTMLInputElement
                && (element.type === 'checkbox' || element.type === 'radio')
                || element instanceof HTMLSelectElement;

            if (usesChangeEvent) {
                attributes['x-on:change'] = syncCall;
            } else if (directive.includes('.throttle')) {
                attributes[`x-on:input.throttle.${options.throttleMs}ms`] = syncCall;
            } else {
                attributes[`x-on:input.debounce.${options.debounceMs}ms`] = syncCall;
            }
        } else if (options.mode === 'blur') {
            attributes['x-on:blur'] = syncCall;
        } else {
            attributes['x-on:change'] = syncCall;
        }

        return attributes;
    }

    function loadingExpression(target) {
        if (!target) {
            return 'isLoading()';
        }

        const targets = target.split(',').map((entry) => entry.trim()).filter(Boolean);
        if (targets.length === 1) {
            return `isLoading('${escapeJsString(targets[0])}')`;
        }

        return `isLoadingAny(${JSON.stringify(targets)})`;
    }

    function compileLoading(directive, target, className, delayMs) {
        const targetLiteral = target
            ? JSON.stringify(target.split(',').map((entry) => entry.trim()).filter(Boolean))
            : 'null';
        const showExpression = delayMs > 0
            ? `isLoadingDelayed(${targetLiteral}, ${delayMs})`
            : (target ? loadingExpression(target) : 'isLoading()');

        if (directive.endsWith('.remove')) {
            return { 'x-show': `!(${showExpression})` };
        }

        if (directive === 'loading.attr' || directive.endsWith('.attr')) {
            return { 'x-bind:disabled': showExpression };
        }

        if (directive === 'loading.class' || directive.endsWith('.class')) {
            const classes = className || 'opacity-50';

            return { 'x-bind:class': `${showExpression} ? '${escapeJsString(classes)}' : ''` };
        }

        return { 'x-show': showExpression };
    }

    function compileDirty(directive, target) {
        const expression = target
            ? `isDirty('${escapeJsString(target)}')`
            : 'isDirty()';

        if (directive.endsWith('.remove')) {
            return { 'x-show': `!(${expression})` };
        }

        if (directive.endsWith('.class')) {
            return { 'x-bind:class': `${expression} ? 'is-dirty' : ''` };
        }

        return { 'x-show': expression };
    }

    function compileEventDirective(directive, expression, confirmMessage) {
        const segments = directive.split('.');
        const eventName = segments[0];
        const modifiers = segments.slice(1);
        if (eventName === 'submit' && !modifiers.includes('prevent')) {
            modifiers.unshift('prevent');
        }

        if (eventName === 'click' && !modifiers.includes('prevent')) {
            modifiers.push('prevent');
        }

        const alpineName = `x-on:${eventName}${modifiers.map((modifier) => `.${modifier}`).join('')}`;

        return { [alpineName]: compileActionHandler(expression, confirmMessage) };
    }

    function isEventDirective(directive) {
        const root = directive.split('.')[0];
        if (RESERVED.has(root)) {
            return false;
        }

        if (directive.startsWith('loading') || directive.startsWith('model') || directive.startsWith('dirty')) {
            return false;
        }

        if (parsePollDirective(directive) !== null) {
            return false;
        }

        return true;
    }

    function wireAttributes(element) {
        return [...element.attributes].filter((attribute) => wireAttributePattern.test(attribute.name));
    }

    function setAlpineAttribute(element, name, expression) {
        if (expression === '' && (name === 'x-model.number' || name === 'x-model.boolean')) {
            element.setAttribute(name, '');
            return;
        }

        const existing = element.getAttribute(name);
        element.setAttribute(name, existing ? `${existing}; ${expression}` : expression);
    }

    function collectPolls(root, polls) {
        root.querySelectorAll('*').forEach((element) => {
            wireAttributes(element).forEach((attribute) => {
                const directive = wireDirectiveName(attribute.name);
                const interval = parsePollDirective(directive);
                if (interval !== null) {
                    polls.push({ interval, action: attribute.value.trim() });
                }
            });
        });
    }

    function applyWireOnAttributes(element, alpine) {
        [...element.attributes].forEach((attribute) => {
            if (!attribute.name.startsWith('wire:on:')) {
                return;
            }

            const eventName = attribute.name.slice('wire:on:'.length);
            const action = attribute.value.trim();
            alpine[`x-on:${eventName}.window`] =
                `handleEvent(${JSON.stringify(eventName)}, ${JSON.stringify(action)}, $event.detail)`;
            element.removeAttribute(attribute.name);
        });
    }

    function applyWireAttributes(element, polls, initActions) {
        const alpine = {};
        applyWireOnAttributes(element, alpine);

        const attributes = wireAttributes(element).filter((attribute) => !attribute.name.startsWith('wire:on:'));

        if (attributes.length === 0 && Object.keys(alpine).length === 0) {
            return;
        }

        const values = Object.fromEntries(attributes.map((attribute) => [attribute.name, attribute.value]));
        const target = values['wire:target'] ?? null;
        const confirmMessage = values['wire:confirm'] ?? null;

        for (const attribute of attributes) {
            const name = attribute.name;
            const directive = wireDirectiveName(name);

            if (name === 'wire:target' || name === 'wire:confirm') {
                continue;
            }

            if (directive.startsWith('model')) {
                Object.assign(alpine, compileModel(name, attribute.value.trim(), element));
            } else if (directive === 'error') {
                const field = attribute.value.trim();
                element.classList.add('cakewire-field-error');
                element.setAttribute('role', 'alert');
                alpine['x-show'] = `hasError('${escapeJsString(field)}')`;
                alpine['x-text'] = `errorMessage('${escapeJsString(field)}')`;
            } else if (directive === 'navigate' || directive.startsWith('navigate.')) {
                const href = element.getAttribute('href');
                if (href) {
                    alpine['x-on:click.prevent'] = `CakeWire.navigate(${JSON.stringify(href)})`;
                }
            } else if (directive.startsWith('loading')) {
                const delayMatch = directive.match(/\.delay(?:\.(\d+)(ms|s))?/);
                const delayMs = delayMatch
                    ? (delayMatch[2] === 's'
                        ? Number.parseInt(delayMatch[1], 10) * 1000
                        : Number.parseInt(delayMatch[1] ?? '200', 10))
                    : 0;
                const className = directive.endsWith('.class') ? attribute.value.trim() : null;
                Object.assign(alpine, compileLoading(directive, target, className, delayMs));
            } else if (directive.startsWith('dirty')) {
                Object.assign(alpine, compileDirty(directive, attribute.value.trim() || target));
            } else if (directive === 'ignore') {
                alpine['x-ignore'] = '';
            } else if (directive === 'cloak') {
                alpine['x-cloak'] = '';
            } else if (directive === 'init') {
                initActions.push(attribute.value.trim());
            } else if (parsePollDirective(directive) !== null) {
                polls.push({ interval: parsePollDirective(directive), action: attribute.value.trim() });
            } else if (isEventDirective(directive)) {
                Object.assign(alpine, compileEventDirective(directive, attribute.value, confirmMessage));
            } else if (directive === 'offline' || directive.startsWith('offline.')) {
                alpine['x-show'] = '!navigator.onLine';
            } else if (directive === 'key') {
                element.setAttribute('data-cakewire-key', attribute.value);
            } else {
                throw new Error(`Unsupported CakeWire directive "${name}".`);
            }
        }

        for (const attribute of attributes) {
            if (attribute.name !== 'wire:key') {
                element.removeAttribute(attribute.name);
            }
        }

        for (const [name, expression] of Object.entries(alpine)) {
            setAlpineAttribute(element, name, expression);
        }
    }

    function bindWire(root) {
        if (!(root instanceof Element)) {
            return { polls: [], initActions: [] };
        }

        const polls = [];
        const initActions = [];

        applyWireAttributes(root, polls, initActions);
        root.querySelectorAll('*').forEach((element) => applyWireAttributes(element, polls, initActions));
        collectPolls(root, polls);

        return { polls, initActions };
    }

    function cloneState(state) {
        return JSON.parse(JSON.stringify(state));
    }

    /**
     * @param {Element} root Component root element.
     * @returns {{ id: string | null, name: string | null, xModel: string | null, selectionStart: number | null, selectionEnd: number | null } | null}
     */
    function captureFocusedField(root) {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement) || !root.contains(active)) {
            return null;
        }

        if (
            !(active instanceof HTMLInputElement) &&
            !(active instanceof HTMLTextAreaElement) &&
            !(active instanceof HTMLSelectElement)
        ) {
            return null;
        }

        return {
            id: active.id || null,
            name: active.name || null,
            xModel: active.getAttribute('x-model'),
            selectionStart: active.selectionStart ?? null,
            selectionEnd: active.selectionEnd ?? null,
        };
    }

    /**
     * @param {Element} root Component root element.
     * @param {{ id: string | null, name: string | null, xModel: string | null, selectionStart: number | null, selectionEnd: number | null } | null} snapshot Focus snapshot.
     * @return {void}
     */
    /**
     * @param {string | null} xModel Alpine x-model expression.
     * @returns {string | null}
     */
    function propertyFromXModel(xModel) {
        if (!xModel || !xModel.startsWith('state.')) {
            return null;
        }

        return xModel.slice('state.'.length);
    }

    /**
     * @param {Node} node DOM node.
     * @returns {string | undefined}
     */
    function morphNodeKey(node) {
        if (!(node instanceof Element)) {
            return undefined;
        }

        const wireKey = node.getAttribute('data-cakewire-key')
            ?? node.getAttribute('wire:key');
        if (wireKey) {
            return `cakewire-key:${wireKey}`;
        }

        if (node.id) {
            return `id:${node.id}`;
        }

        return undefined;
    }

    /**
     * @param {Element} fromEl Existing element.
     * @param {Element} toEl Desired element from the server HTML.
     * @returns {boolean} False to skip updating this node.
     */
    function shouldMorphElementUpdate(fromEl, toEl) {
        if (fromEl.hasAttribute('x-ignore')) {
            return false;
        }

        if (fromEl === document.activeElement) {
            return false;
        }

        if (fromEl instanceof HTMLInputElement && fromEl.type === 'file') {
            return false;
        }

        if (fromEl.isEqualNode(toEl)) {
            return false;
        }

        return true;
    }

    /**
     * @param {Element} root Component root (`x-data="cakewireShell()"`).
     * @param {string} html Rendered HTML from the server.
     * @returns {Element} Root whose children were morphed.
     */
    function morphComponentDom(root, html) {
        const template = document.createElement('div');
        template.innerHTML = html;

        const morph = typeof window.morphdom === 'function' ? window.morphdom : null;
        if (!morph) {
            root.innerHTML = html;

            return root;
        }

        morph(root, template, {
            childrenOnly: true,
            getNodeKey: morphNodeKey,
            onBeforeElUpdated(fromEl, toEl) {
                if (!(fromEl instanceof Element) || !(toEl instanceof Element)) {
                    return true;
                }

                return shouldMorphElementUpdate(fromEl, toEl);
            },
        });

        return root;
    }

    /**
     * @param {Element} currentRoot Existing container.
     * @param {Element} nextRoot Parsed HTML container.
     * @return {void}
     */
    function morphNavigateRoot(currentRoot, nextRoot) {
        const morph = typeof window.morphdom === 'function' ? window.morphdom : null;
        if (!morph) {
            currentRoot.innerHTML = nextRoot.innerHTML;

            return;
        }

        morph(currentRoot, nextRoot, {
            childrenOnly: true,
            getNodeKey: morphNodeKey,
            onBeforeElUpdated(fromEl, toEl) {
                if (!(fromEl instanceof Element) || !(toEl instanceof Element)) {
                    return true;
                }

                return shouldMorphElementUpdate(fromEl, toEl);
            },
        });
    }

    function restoreFocusedField(root, snapshot) {
        if (!snapshot) {
            return;
        }

        /** @type {HTMLElement | null} */
        let element = null;
        if (snapshot.id) {
            element = root.querySelector(`#${CSS.escape(snapshot.id)}`);
        }
        if (!element && snapshot.name) {
            element = root.querySelector(`[name="${CSS.escape(snapshot.name)}"]`);
        }
        if (!element && snapshot.xModel) {
            element = root.querySelector(`[x-model="${snapshot.xModel}"]`);
        }

        if (!(element instanceof HTMLElement)) {
            return;
        }

        element.focus({ preventScroll: true });

        if (
            (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) &&
            snapshot.selectionStart !== null &&
            snapshot.selectionEnd !== null
        ) {
            try {
                element.setSelectionRange(snapshot.selectionStart, snapshot.selectionEnd);
            } catch (error) {
                // Some input types do not support selection ranges.
            }
        }
    }

    function createComponent(options) {
        let requestGeneration = 0;

        return {
            state: options.state,
            serverState: cloneState(options.state),
            busy: false,
            target: null,
            syncProperty: null,
            loadingSince: null,
            pollTimers: [],
            pendingUploads: {},

            init() {
                const root = this.$root ?? this.__el;
                const { polls, initActions } = bindWire(root);
                this.startPolls(polls);
                this.runInitActions(initActions);
            },

            destroy() {
                this.pollTimers.forEach((timer) => window.clearInterval(timer));
            },

            startPolls(polls) {
                polls.forEach((poll) => {
                    const parsed = parseActionExpression(poll.action);
                    const timer = window.setInterval(() => {
                        if (parsed.type === 'refresh') {
                            this.refresh();
                        } else {
                            this.runAction(parsed.action, ...parsed.params);
                        }
                    }, poll.interval);
                    this.pollTimers.push(timer);
                });
            },

            runInitActions(actions) {
                actions.forEach((expression) => {
                    const parsed = parseActionExpression(expression);
                    if (parsed.type === 'refresh') {
                        this.refresh();
                    } else {
                        this.runAction(parsed.action, ...parsed.params);
                    }
                });
            },

            async refresh() {
                await this.request({});
            },

            async runAction(action, ...params) {
                this.target = action;
                try {
                    await this.request({ action, params });
                } finally {
                    this.target = null;
                }
            },

            async call(action, ...params) {
                return this.runAction(action, ...params);
            },

            async sync(property = null) {
                this.syncProperty = property;
                try {
                    await this.request({});
                } finally {
                    this.syncProperty = null;
                }
            },

            async upload(property, event) {
                const input = event.target;
                if (!(input instanceof HTMLInputElement) || !input.files || input.files.length === 0) {
                    return;
                }

                this.pendingUploads[property] = input.files[0];
                await this.sync(property);
            },

            handleEvent(eventName, action, detail) {
                const params = detail !== undefined && detail !== null ? [detail] : [];

                return this.runAction(action, ...params);
            },

            hasError(field) {
                return Boolean(this.state._errors?.[field]);
            },

            errorMessage(field) {
                return this.state._errors?.[field] ?? '';
            },

            isLoading(action = null) {
                if (!this.busy) {
                    return false;
                }

                if (action === null || action === undefined || action === '') {
                    return true;
                }

                return this.target === action || this.syncProperty === action;
            },

            isLoadingAny(actions) {
                return actions.some((action) => this.isLoading(action));
            },

            isLoadingDelayed(targets, delayMs) {
                if (!this.busy || this.loadingSince === null) {
                    return false;
                }

                if (Date.now() - this.loadingSince < delayMs) {
                    return false;
                }

                if (targets === null) {
                    return true;
                }

                if (Array.isArray(targets)) {
                    return this.isLoadingAny(targets);
                }

                return this.isLoading(targets);
            },

            isDirty(property = null) {
                if (property) {
                    return JSON.stringify(this.state[property]) !== JSON.stringify(this.serverState[property]);
                }

                return JSON.stringify(this.state) !== JSON.stringify(this.serverState);
            },

            async request(update) {
                const generation = ++requestGeneration;

                this.busy = true;
                this.loadingSince = Date.now();
                try {
                    const payloadBody = {
                        component: options.component,
                        state: this.state,
                        syncProperty: this.syncProperty ?? null,
                        ...update,
                    };
                    const uploadEntries = Object.entries(this.pendingUploads ?? {});
                    const headers = {
                        Accept: 'application/json',
                        'X-CSRF-Token': this.csrfToken(),
                    };

                    let body;
                    if (uploadEntries.length > 0) {
                        body = new FormData();
                        body.append('payload', JSON.stringify(payloadBody));
                        uploadEntries.forEach(([field, file]) => {
                            body.append(field, file);
                        });
                    } else {
                        headers['Content-Type'] = 'application/json';
                        body = JSON.stringify(payloadBody);
                    }

                    const response = await fetch(options.endpoint, {
                        method: 'POST',
                        headers,
                        body,
                    });
                    if (!response.ok) {
                        throw new Error(`CakeWire update failed with HTTP ${response.status}.`);
                    }

                    const payload = await response.json();
                    if (generation !== requestGeneration) {
                        return;
                    }

                    this.pendingUploads = {};
                    const root = this.$root ?? this.__el;
                    const focusSnapshot = captureFocusedField(root);
                    const focusedProperty = propertyFromXModel(focusSnapshot?.xModel ?? null);
                    const preservedValue =
                        focusedProperty !== null && Object.prototype.hasOwnProperty.call(this.state, focusedProperty)
                            ? this.state[focusedProperty]
                            : undefined;

                    this.state = payload.state;
                    this.serverState = cloneState(payload.state);
                    if (focusedProperty !== null && preservedValue !== undefined) {
                        this.state[focusedProperty] = preservedValue;
                        this.serverState[focusedProperty] = preservedValue;
                    }

                    const patchRoot = morphComponentDom(root, payload.html);
                    bindWire(patchRoot);
                    if (window.Alpine) {
                        window.Alpine.initTree(patchRoot);
                    }
                    restoreFocusedField(root, focusSnapshot);
                } finally {
                    this.busy = false;
                    this.loadingSince = null;
                }
            },

            csrfToken() {
                const token = document.querySelector('meta[name="csrfToken"]')?.content;
                if (token) {
                    return token;
                }
                const match = document.cookie.match(/(?:^|;\s*)csrfToken=([^;]+)/);
                return match ? decodeURIComponent(match[1]) : '';
            },
        };
    }

    function createWireProxy(component) {
        return new Proxy(component, {
            get(target, property) {
                if (property === 'get') {
                    return (name) => target.state[name];
                }
                if (property === 'set') {
                    return (name, value) => {
                        target.state[name] = value;
                        return target.sync(name);
                    };
                }
                if (property in target.state) {
                    return target.state[property];
                }
                if (typeof target[property] === 'function') {
                    return target[property].bind(target);
                }

                return undefined;
            },
            set(target, property, value) {
                if (property in target.state) {
                    target.state[property] = value;
                    return true;
                }

                target[property] = value;
                return true;
            },
        });
    }

    function dispatch(eventName, detail = {}) {
        window.dispatchEvent(new CustomEvent(eventName, { detail, bubbles: true }));
    }

    function dispatchTo(eventName, detail = {}, alias) {
        const target = document.querySelector(`[data-cakewire-alias="${alias}"]`);
        const event = new CustomEvent(eventName, { detail, bubbles: true });
        if (target) {
            target.dispatchEvent(event);
            return;
        }

        window.dispatchEvent(event);
    }

    /**
     * @param {MouseEvent} event Click event.
     * @return {boolean} True when navigation was handled.
     */
    function handleNavigateClick(event) {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return false;
        }

        const target = event.target;
        if (!(target instanceof Element)) {
            return false;
        }

        const anchor = target.closest('a[href]');
        if (!(anchor instanceof HTMLAnchorElement)) {
            return false;
        }

        if (!anchor.hasAttribute('wire:navigate')) {
            return false;
        }

        if (anchor.target && anchor.target !== '_self') {
            return false;
        }

        const href = anchor.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
            return false;
        }

        let url;
        try {
            url = new URL(href, window.location.href);
        } catch (error) {
            return false;
        }

        if (url.origin !== window.location.origin) {
            return false;
        }

        event.preventDefault();
        navigate(url.href);

        return true;
    }

    function setupWireNavigateDelegation() {
        if (document.documentElement.dataset.cakewireNavigateDelegation === '1') {
            return;
        }

        document.documentElement.dataset.cakewireNavigateDelegation = '1';
        document.addEventListener('click', handleNavigateClick);
    }

    async function navigate(url, pushState = true) {
        const response = await fetch(url, {
            headers: {
                Accept: 'text/html',
                'X-CakeWire-Navigate': '1',
            },
        });
        if (!response.ok) {
            window.location.href = url;
            return;
        }

        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const nextMain = parsed.querySelector('[data-cakewire-navigate]');
        const currentMain = document.querySelector('[data-cakewire-navigate]');
        if (!nextMain || !currentMain) {
            window.location.href = url;
            return;
        }

        morphNavigateRoot(currentMain, nextMain);
        const title = parsed.querySelector('title');
        if (title) {
            document.title = title.textContent ?? document.title;
        }

        currentMain.querySelectorAll('[data-cakewire-config]').forEach((element) => {
            bindWire(element);
        });
        if (window.Alpine) {
            window.Alpine.initTree(currentMain);
        }

        if (pushState) {
            window.history.pushState({ cakewireNavigate: true }, '', url);
        }
    }

    function registerAlpinePlugin() {
        if (!window.Alpine) {
            return;
        }

        window.Alpine.data('cakeWire', (options) => createComponent(options));
        window.Alpine.magic('wire', (el) => {
            const host = el.closest('[data-cakewire-config]');
            if (!host) {
                return null;
            }

            return createWireProxy(window.Alpine.$data(host));
        });
    }

    function readConfig(element) {
        const encoded = element.getAttribute('data-cakewire-config');
        if (!encoded) {
            throw new Error('CakeWire configuration is missing on the component element.');
        }

        return JSON.parse(encoded);
    }

    const SHELL_METHODS = new Set([
        'handleWireClick',
        'handleWireSubmit',
        'handleWireRefresh',
        'handleWireSync',
        'handleWireEvent',
        'wireIsLoading',
        'wireIsLoadingAny',
        'wireHasError',
        'wireErrorMessage',
        'init',
    ]);

    function applyBlueprint(data, element) {
        const blueprint = createComponent(readConfig(element));
        data.$root = element;
        data.__el = element;

        Object.keys(blueprint).forEach((key) => {
            if (SHELL_METHODS.has(key)) {
                return;
            }

            const value = blueprint[key];
            if (typeof value === 'function') {
                data[key] = value.bind(data);
            } else {
                data[key] = value;
            }
        });

        if (typeof blueprint.init === 'function') {
            blueprint.init.call(data);
        }
    }

    function shell() {
        return {
            state: {},
            busy: false,
            init() {
                applyBlueprint(this, this.$el);
            },
            handleWireClick(event, action, ...params) {
                if (typeof this.runAction !== 'function') {
                    throw new Error('CakeWire is not mounted yet.');
                }

                return this.runAction(action, ...params).catch((error) => alert(error.message));
            },
            handleWireSubmit(event, action, ...params) {
                event.preventDefault();
                return this.handleWireClick(event, action, ...params);
            },
            handleWireRefresh(event) {
                if (event?.preventDefault) {
                    event.preventDefault();
                }
                if (typeof this.refresh !== 'function') {
                    throw new Error('CakeWire is not mounted yet.');
                }

                return this.refresh().catch((error) => alert(error.message));
            },
            handleWireSync(property) {
                if (typeof this.sync !== 'function') {
                    throw new Error('CakeWire is not mounted yet.');
                }

                return this.sync(property);
            },
            handleWireEvent(eventName, action, detail) {
                if (typeof this.handleEvent !== 'function') {
                    throw new Error('CakeWire is not mounted yet.');
                }

                return this.handleEvent(eventName, action, detail);
            },
            wireIsLoading(target = null) {
                if (typeof this.isLoading !== 'function') {
                    return false;
                }

                return this.isLoading(target);
            },
            wireIsLoadingAny(targets) {
                if (typeof this.isLoadingAny !== 'function') {
                    return false;
                }

                return this.isLoadingAny(targets);
            },
            wireHasError(field) {
                if (typeof this.hasError !== 'function') {
                    return false;
                }

                return this.hasError(field);
            },
            wireErrorMessage(field) {
                if (typeof this.errorMessage !== 'function') {
                    return '';
                }

                return this.errorMessage(field);
            },
        };
    }

    function mount(data, element) {
        applyBlueprint(data, element);
    }

    function fromElement(element) {
        return createComponent(readConfig(element));
    }

    window.cakeWire = (options) => createComponent(options);
    window.CakeWire = {
        bindWire,
        morphComponentDom,
        component: createComponent,
        shell,
        mount,
        fromElement,
        navigate,
        dispatch,
        dispatchTo,
    };

    function prepareDom(root = document) {
        root.querySelectorAll('[data-cakewire-config]').forEach((element) => {
            bindWire(element);
        });
    }

    setupWireNavigateDelegation();

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('cakewireShell', shell);
        prepareDom();
        registerAlpinePlugin();
    });

    window.addEventListener('popstate', () => {
        if (window.location.pathname) {
            navigate(window.location.href, false);
        }
    });
})();
