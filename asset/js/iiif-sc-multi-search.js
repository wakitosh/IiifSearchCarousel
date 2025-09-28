// Minimal multi-search for IiifSearchCarousel (use fulltext_search name; other params like logic are left intact)
(function () {
    if (window.iiifScMultiSearchInstalled) return; // prevent double-install
    window.iiifScMultiSearchInstalled = true;
    function qs(el, sel) { return el.querySelector(sel); }
    function qsa(el, sel) { return Array.from(el.querySelectorAll(sel)); }

    const STORAGE_KEY = 'iiif-sc.logic'; // 'and' | 'or'

    function getUrlLogic() {
        try {
            const sp = new URLSearchParams(window.location.search);
            const v = (sp.get('fulltext_logic') || sp.get('logic') || '').toLowerCase();
            return (v === 'or' || v === 'and') ? v : null;
        } catch (_) { return null; }
    }

    function loadSavedLogic() {
        try {
            const v = (window.localStorage && window.localStorage.getItem(STORAGE_KEY)) || '';
            const val = String(v).toLowerCase();
            return (val === 'or' || val === 'and') ? val : null;
        } catch (_) { return null; }
    }

    function saveLogic(val) {
        try {
            if (val === 'and' || val === 'or') {
                window.localStorage && window.localStorage.setItem(STORAGE_KEY, val);
            }
        } catch (_) { /* ignore */ }
    }

    function applyLogicToForm(form, logic) {
        if (!logic) return;
        const radios = qsa(form, 'input[type="radio"][name="logic"]');
        if (!radios.length) return;
        let found = false;
        radios.forEach(r => {
            if (String(r.value).toLowerCase() === logic) {
                r.checked = true;
                found = true;
            }
        });
        return found;
    }

    function wireLogicPersistence(form) {
        const radios = qsa(form, 'input[type="radio"][name="logic"]');
        if (!radios.length) return;
        radios.forEach(r => {
            r.addEventListener('change', function () {
                try { saveLogic(String(r.value).toLowerCase()); } catch (_) { /* ignore */ }
            });
        });
    }

    function onSubmit(form) {
        const input = qs(form, 'input[type="search"], input[name="fulltext_search"], input#multi-search-input');
        const raw = input ? String(input.value || '') : '';
        // Align with site search: always send fulltext_search only
        if (input) input.name = 'fulltext_search';
        return true;
    }

    function init() {
        const urlLogic = getUrlLogic();
        const savedLogic = urlLogic ? null : loadSavedLogic(); // URL優先、なければ保存値
        const forms = document.querySelectorAll('form.iiif-sc__search');
        forms.forEach(form => {
            // 1) ラジオの永続化
            try { wireLogicPersistence(form); } catch (_) { /* ignore */ }
            // 2) 復元（URLがあればURL、なければ保存値）
            try { applyLogicToForm(form, urlLogic || savedLogic); } catch (_) { /* ignore */ }
            // 3) 送信時の name 正規化
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
