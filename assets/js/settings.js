/**
 * Smart Gateway settings category navigation.
 * Keeps settings pages compact and preserves the selected category in the URL.
 */
(function () {
    'use strict';

    function initSettingsCategories() {
        const nav = document.querySelector('.sg-settings-category-nav');
        const panels = Array.from(document.querySelectorAll('.sg-settings-panel'));
        if (!nav || !panels.length) return;

        const buttons = Array.from(nav.querySelectorAll('[data-settings-target]'));
        const params = new URLSearchParams(window.location.search);
        const requested = params.get('category');
        const first = panels[0].dataset.settingsCategory;
        const initial = panels.some(p => p.dataset.settingsCategory === requested) ? requested : first;

        function showCategory(category, updateUrl = true) {
            const valid = panels.some(p => p.dataset.settingsCategory === category);
            if (!valid) category = first;

            panels.forEach(panel => {
                const active = panel.dataset.settingsCategory === category;
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });

            buttons.forEach(button => {
                const active = button.dataset.settingsTarget === category;
                button.classList.toggle('active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            if (updateUrl) {
                const next = new URL(window.location.href);
                next.searchParams.set('category', category);
                window.history.replaceState({}, '', next);
            }
        }

        buttons.forEach(button => {
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', 'false');
            button.addEventListener('click', () => showCategory(button.dataset.settingsTarget));
        });

        showCategory(initial, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettingsCategories);
    } else {
        initSettingsCategories();
    }
})();
