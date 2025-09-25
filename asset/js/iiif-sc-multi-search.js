// Minimal standalone multi-search for IiifSearchCarousel
// - Tokenize input (supports quoted phrases)
// - Build Any Property property[] params for multi-term
// - Persist AND/OR radios via localStorage
(function () {
    if (window.iiifScMultiSearchInstalled) return; // prevent double-install
    window.iiifScMultiSearchInstalled = true;

    function tokenize(s) {
        const out = []; if (!s) return out; s = s.trim(); if (!s) return out;
        const re = /"([^"]+)"|'([^']+)'|(\S+)/g; let m;
        while ((m = re.exec(s))) { out.push(m[1] || m[2] || m[3]); }
        return out;
    }
    function qs(el, sel) { return el.querySelector(sel); }
    function qsa(el, sel) { return Array.from(el.querySelectorAll(sel)); }

    function getLogic(form) {
        // Always enforce AND regardless of UI/localStorage
        return 'and';
    }
    function persistLogic(form) {
        const radios = qsa(form, 'input[name="logic"]');
        // Force-check AND and uncheck/disable OR defensively
        try {
            const andRadio = qs(form, 'input[name="logic"][value="and"]');
            const orRadio = qs(form, 'input[name="logic"][value="or"]');
            if (andRadio) { andRadio.checked = true; }
            if (orRadio) { orRadio.checked = false; orRadio.disabled = true; }
            localStorage.setItem('iiifScMultiSearchLogic', 'and');
        } catch (e) { }
        // Still attach listeners to keep storage in sync if HTML changes later
        radios.forEach(r => r.addEventListener('change', () => {
            try { localStorage.setItem('iiifScMultiSearchLogic', 'and'); } catch (e) { }
            try {
                const andRadio = qs(form, 'input[name="logic"][value="and"]');
                const orRadio = qs(form, 'input[name="logic"][value="or"]');
                if (andRadio) { andRadio.checked = true; }
                if (orRadio) { orRadio.checked = false; }
            } catch (e) { }
        }));
    }

    function clearHidden(container) {
        while (container.firstChild) container.removeChild(container.firstChild);
    }
    function appendHidden(container, name, value) {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = name; input.value = value;
        container.appendChild(input);
    }

    function onSubmit(form) {
        const input = qs(form, 'input[type="search"], input[name="search"], input#multi-search-input');
        const hiddenBox = qs(form, '#multi-search-hidden') || (function () {
            const d = document.createElement('div'); d.id = 'multi-search-hidden'; d.style.display = 'none'; d.setAttribute('aria-hidden', 'true');
            form.appendChild(d); return d;
        })();
        clearHidden(hiddenBox);
        const raw = input ? String(input.value || '') : '';
        const tokens = tokenize(raw);
        const logic = getLogic(form); // always 'and'
        // sentinel to avoid reprocessing by other scripts
        appendHidden(hiddenBox, 'multi_search_applied', '1');
        if (tokens.length <= 1) {
            // Keep single-term as is (name=search)
            if (input) input.name = 'search';
            return true;
        }
        // Multi-term: remove name from input to avoid conflicting search=
        if (input) input.removeAttribute('name');
        tokens.forEach((t, i) => {
            const base = 'property[' + i + ']';
            appendHidden(hiddenBox, base + '[property]', '');
            appendHidden(hiddenBox, base + '[type]', 'in');
            appendHidden(hiddenBox, base + '[text]', t);
            appendHidden(hiddenBox, base + '[joiner]', 'and');
        });
        return true;
    }

    function init() {
        const forms = document.querySelectorAll('form.iiif-sc__search');
        forms.forEach(form => {
            persistLogic(form);
            form.addEventListener('submit', function (ev) {
                try { onSubmit(form); } catch (e) { /* fail-open */ }
            }, { capture: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
