// Minimal multi-search for IiifSearchCarousel (use fulltext_search name; other params like logic are left intact)
(function () {
    if (window.iiifScMultiSearchInstalled) return; // prevent double-install
    window.iiifScMultiSearchInstalled = true;
    function qs(el, sel) { return el.querySelector(sel); }

    function onSubmit(form) {
        const input = qs(form, 'input[type="search"], input[name="fulltext_search"], input#multi-search-input');
        const raw = input ? String(input.value || '') : '';
        // Align with site search: always send fulltext_search only
        if (input) input.name = 'fulltext_search';
        return true;
    }

    function init() {
        const forms = document.querySelectorAll('form.iiif-sc__search');
        forms.forEach(form => {
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
