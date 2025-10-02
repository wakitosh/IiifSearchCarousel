// Minimal multi-search for IiifSearchCarousel (use fulltext_search name; other params like logic are left intact)
(function () {
    if (window.iiifScMultiSearchInstalled) return; // prevent double-install
    window.iiifScMultiSearchInstalled = true;

    function qs(el, sel) { return el.querySelector(sel); }
    function qsa(el, sel) { return Array.from(el.querySelectorAll(sel)); }

    // Known storage keys for backward-compat and cross-module sharing
    const LS_KEYS = [
        'wakitosh.SearchLogic',            // official
        'iiif-sc.logic',                   // legacy (carousel)
        'theme.foundation_tsukuba2025.logic' // legacy (theme)
    ];

    function setCookie(name, value, days) {
        try {
            const d = new Date();
            d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
            const expires = 'expires=' + d.toUTCString();
            document.cookie = name + '=' + encodeURIComponent(value) + ';' + expires + ';path=/;SameSite=Lax';
        } catch (_) { }
    }
    function getCookie(name) {
        try {
            const nameEQ = name + '=';
            const ca = document.cookie ? document.cookie.split(';') : [];
            for (let i = 0; i < ca.length; i++) {
                const c = ca[i].trim();
                if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length));
            }
        } catch (_) { }
        return '';
    }

    function getUrlLogic() {
        try {
            const sp = new URLSearchParams(window.location.search);
            const v = (sp.get('fulltext_logic') || sp.get('logic') || '').toLowerCase();
            return (v === 'or' || v === 'and') ? v : null;
        } catch (_) { return null; }
    }

    function loadSavedLogic() {
        // Priority: URL (handled separately) > window globals > cookies > localStorage keys
        try {
            const g = (window.wakitoshSearchLogic || window.__OMK_SEARCH_LOGIC || '').toLowerCase();
            if (g === 'and' || g === 'or') return g;
        } catch (_) { }
        try {
            const cv = (getCookie('wakitosh.SearchLogic') || getCookie('omk_search_logic') || '').toLowerCase();
            if (cv === 'and' || cv === 'or') return cv;
        } catch (_) { }
        try {
            if (window.localStorage) {
                for (let i = 0; i < LS_KEYS.length; i++) {
                    const v = (window.localStorage.getItem(LS_KEYS[i]) || '').toLowerCase();
                    if (v === 'and' || v === 'or') return v;
                }
            }
        } catch (_) { }
        return null;
    }

    function saveLogic(val) {
        // Write to globals, cookie (180d), and LS keys (official + legacy)
        try { window.wakitoshSearchLogic = val; window.__OMK_SEARCH_LOGIC = val; } catch (_) { }
        try { if (val === 'and' || val === 'or') setCookie('wakitosh.SearchLogic', val, 180); } catch (_) { }
        try {
            if (window.localStorage && (val === 'and' || val === 'or')) {
                for (let i = 0; i < LS_KEYS.length; i++) {
                    try { window.localStorage.setItem(LS_KEYS[i], val); } catch (_) { }
                }
            }
        } catch (_) { }
    }

    function getSelectedLogic(form) {
        const r = qs(form, 'input[type="radio"][name="logic"]:checked');
        const v = r ? String(r.value).toLowerCase() : '';
        return (v === 'and' || v === 'or') ? v : null;
    }

    function applyLogicToForm(form, logic) {
        if (!logic) return false;
        const radios = qsa(form, 'input[type="radio"][name="logic"]');
        if (!radios.length) return false;
        let found = false;
        radios.forEach(r => {
            const match = String(r.value).toLowerCase() === logic;
            r.checked = match || r.checked && !found; // ensure some radio remains selected
            if (match) found = true;
        });
        return found;
    }

    function setHiddenFulltextLogic(form, logic) {
        try {
            if (!logic) return;
            let hiddenHost = qs(form, '#multi-search-hidden');
            if (!hiddenHost) hiddenHost = form; // fallback
            let hid = qs(hiddenHost, 'input[type="hidden"][name="fulltext_logic"]');
            if (!hid) {
                hid = document.createElement('input');
                hid.type = 'hidden';
                hid.name = 'fulltext_logic';
                hiddenHost.appendChild(hid);
            }
            hid.value = logic;
        } catch (_) { /* ignore */ }
    }

    function updateLinksLogic(root, logic) {
        if (!logic) return;
        const links = qsa(root, 'a.iiif-sc__example, a.iiif-sc__advanced-link');
        links.forEach(a => {
            try {
                const u = new URL(a.getAttribute('href'), window.location.origin);
                // Normalize params: prefer fulltext_logic, also set logic for compatibility
                u.searchParams.set('fulltext_logic', logic);
                u.searchParams.set('logic', logic);
                a.setAttribute('href', u.pathname + (u.search || '') + (u.hash || ''));
            } catch (_) { /* ignore broken href */ }
        });
    }

    function wireLogicPersistence(form) {
        const radios = qsa(form, 'input[type="radio"][name="logic"]');
        if (!radios.length) return;
        radios.forEach(r => {
            r.addEventListener('change', function () {
                const val = String(r.value).toLowerCase();
                try {
                    saveLogic(val);
                    setHiddenFulltextLogic(form, val);
                    updateLinksLogic(form, val);
                } catch (_) { /* ignore */ }
            });
        });
    }

    function onSubmit(form) {
        const input = qs(form, 'input[type="search"], input[name="fulltext_search"], input#multi-search-input');
        // Align with site search: always send fulltext_search only
        if (input) input.name = 'fulltext_search';
        // Mirror fulltext_logic hidden param
        const logic = getSelectedLogic(form) || loadSavedLogic() || 'and';
        setHiddenFulltextLogic(form, logic);
        return true;
    }

    function init() {
        const urlLogic = getUrlLogic();
        const savedLogic = urlLogic ? null : loadSavedLogic(); // URL優先、なければ保存値
        const logicToApply = urlLogic || savedLogic;
        const forms = document.querySelectorAll('form.iiif-sc__search');
        forms.forEach(form => {
            // 1) ラジオの永続化 + 初期同期
            try { wireLogicPersistence(form); } catch (_) { /* ignore */ }
            // 2) 復元（URLがあればURL、なければ保存値）
            try {
                if (applyLogicToForm(form, logicToApply)) {
                    if (logicToApply) saveLogic(logicToApply);
                    setHiddenFulltextLogic(form, logicToApply);
                    updateLinksLogic(form, logicToApply);
                }
            } catch (_) { /* ignore */ }
            // 3) 送信時の name 正規化 + hidden 反映
            form.addEventListener('submit', function () {
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
