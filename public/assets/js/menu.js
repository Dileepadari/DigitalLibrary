/*
 * The account menu is a <details>, so it opens and closes with no script at
 * all. This only adds the two things a <details> cannot do on its own: close
 * when the click lands somewhere else, and close on Escape.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var menus = document.querySelectorAll('[data-menu]');

        if (menus.length === 0) {
            return;
        }

        function closeAll(except) {
            Array.prototype.forEach.call(menus, function (menu) {
                if (menu !== except) {
                    menu.removeAttribute('open');
                }
            });
        }

        document.addEventListener('click', function (event) {
            var inside = null;

            Array.prototype.forEach.call(menus, function (menu) {
                if (menu.contains(event.target)) {
                    inside = menu;
                }
            });

            closeAll(inside);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            Array.prototype.forEach.call(menus, function (menu) {
                if (menu.hasAttribute('open')) {
                    menu.removeAttribute('open');

                    var trigger = menu.querySelector('summary');

                    if (trigger) {
                        trigger.focus();
                    }
                }
            });
        });
    });
})();
