/*
 * Theme switch. Loaded synchronously in the head so the stored choice is on the
 * document before first paint, which is what stops the light-to-dark flash.
 * Three states: "light", "dark", and no stored value meaning follow the system.
 */
(function () {
    'use strict';

    var KEY = 'dl.theme';

    function stored() {
        try {
            return localStorage.getItem(KEY);
        } catch (e) {
            return null;
        }
    }

    function apply(theme) {
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.setAttribute('data-theme', theme);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
    }

    function current() {
        var explicit = document.documentElement.getAttribute('data-theme');

        if (explicit) {
            return explicit;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    apply(stored());

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.querySelector('[data-theme-toggle]');

        if (!button) {
            return;
        }

        var label = button.querySelector('[data-theme-icon]') || button;

        function paint() {
            label.textContent = current() === 'dark' ? 'Light mode' : 'Dark mode';
        }

        paint();

        button.addEventListener('click', function () {
            var next = current() === 'dark' ? 'light' : 'dark';

            apply(next);

            try {
                localStorage.setItem(KEY, next);
            } catch (e) {
                // Private window or blocked storage: the choice lasts for this page only.
            }

            paint();
        });
    });
})();
