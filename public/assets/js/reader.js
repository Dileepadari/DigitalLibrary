/*
 * The in-browser reader.
 *
 * Everything it needs is on the [data-reader] element: which kind of file, where
 * to fetch it, where the person got to last time, and the CSRF token for saving
 * progress. The libraries are vendored under /assets/vendor because the
 * Content-Security-Policy allows scripts from this origin only.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-reader]');

    if (!root || root.dataset.kind === 'none') {
        return;
    }

    var viewport = root.querySelector('[data-reader-viewport]');
    var status = root.querySelector('[data-reader-status]');
    var where = root.querySelector('[data-reader-where]');
    var errorBox = root.querySelector('[data-reader-error]');
    var positionField = root.querySelector('[data-reader-position]');
    var prev = root.querySelector('[data-reader-prev]');
    var next = root.querySelector('[data-reader-next]');

    var lastSaved = '';
    var lastSentAt = 0;

    function fail(message) {
        if (status) {
            status.remove();
        }

        if (errorBox) {
            errorBox.textContent = message;
            errorBox.hidden = false;
        }
    }

    function said(text) {
        if (where) {
            where.textContent = text;
        }
    }

    /** Remembers the place, for the bookmark form and for the server. */
    function at(position, percent) {
        if (positionField) {
            positionField.value = position;
        }

        var now = Date.now();

        // Saving on every page turn would be a request per keypress.
        if (position === lastSaved || now - lastSentAt < 4000) {
            return;
        }

        lastSaved = position;
        lastSentAt = now;
        save(position, percent, false);
    }

    function save(position, percent, leaving) {
        if (!position) {
            return;
        }

        try {
            fetch(root.dataset.progressUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': root.dataset.token
                },
                body: JSON.stringify({ position: String(position), percent: Math.round(percent || 0) }),
                keepalive: leaving === true
            });
        } catch (e) {
            // A failed save is not worth interrupting someone's reading.
        }
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var element = document.createElement('script');
            element.src = src;
            element.onload = resolve;
            element.onerror = function () {
                reject(new Error('Could not load ' + src));
            };
            document.head.appendChild(element);
        });
    }

    function onKeys(back, forward) {
        document.addEventListener('keydown', function (event) {
            if (event.target && /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName)) {
                return;
            }

            if (event.key === 'ArrowLeft' || event.key === 'PageUp' || event.key === 'k') {
                back();
            } else if (event.key === 'ArrowRight' || event.key === 'PageDown' || event.key === 'j'
                || event.key === ' ') {
                forward();
            }
        });
    }

    function onGoto(handler) {
        root.querySelectorAll('[data-reader-goto]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                handler(link.dataset.readerGoto);
            });
        });
    }

    /* PDF ------------------------------------------------------------- */

    function startPdf() {
        import(new URL('/assets/vendor/pdf.min.mjs', window.location.origin).href).then(function (pdfjs) {
            pdfjs.GlobalWorkerOptions.workerSrc = root.dataset.worker;

            return pdfjs.getDocument({ url: root.dataset.file, withCredentials: true }).promise;
        }).then(function (pdf) {
            var canvas = document.createElement('canvas');
            canvas.className = 'reader__canvas';

            if (status) {
                status.remove();
            }

            viewport.appendChild(canvas);

            var page = Math.min(Math.max(parseInt(root.dataset.position, 10) || 1, 1), pdf.numPages);
            var rendering = false;

            function draw() {
                if (rendering) {
                    return;
                }

                rendering = true;

                pdf.getPage(page).then(function (rendered) {
                    var unscaled = rendered.getViewport({ scale: 1 });
                    var scale = Math.min(1.8, (viewport.clientWidth - 24) / unscaled.width);
                    var viewportAt = rendered.getViewport({ scale: Math.max(scale, 0.3) });

                    canvas.width = viewportAt.width;
                    canvas.height = viewportAt.height;

                    return rendered.render({
                        canvasContext: canvas.getContext('2d'),
                        viewport: viewportAt
                    }).promise;
                }).then(function () {
                    rendering = false;
                    said('Page ' + page + ' of ' + pdf.numPages);
                    at(String(page), (page / pdf.numPages) * 100);
                }).catch(function (error) {
                    rendering = false;
                    fail('That page could not be drawn: ' + error.message);
                });
            }

            function go(to) {
                page = Math.min(Math.max(to, 1), pdf.numPages);
                draw();
            }

            prev.addEventListener('click', function () {
                go(page - 1);
            });
            next.addEventListener('click', function () {
                go(page + 1);
            });
            onKeys(function () {
                go(page - 1);
            }, function () {
                go(page + 1);
            });
            onGoto(function (position) {
                go(parseInt(position, 10) || 1);
            });

            window.addEventListener('resize', draw);
            window.addEventListener('pagehide', function () {
                save(String(page), (page / pdf.numPages) * 100, true);
            });

            draw();
        }).catch(function (error) {
            fail('This PDF could not be opened: ' + error.message);
        });
    }

    /* EPUB ------------------------------------------------------------ */

    function startEpub() {
        loadScript('/assets/vendor/jszip.min.js').then(function () {
            return loadScript('/assets/vendor/epub.min.js');
        }).then(function () {
            var book = window.ePub(root.dataset.file, { openAs: 'epub' });
            var rendition = book.renderTo(viewport, { width: '100%', height: '100%', spread: 'none' });

            if (status) {
                status.remove();
            }

            rendition.display(root.dataset.position || undefined);

            rendition.on('relocated', function (location) {
                var percent = location.start.percentage ? location.start.percentage * 100 : 0;
                said(location.start.displayed
                    ? 'Page ' + location.start.displayed.page + ' of ' + location.start.displayed.total
                    : '');
                at(location.start.cfi, percent);
            });

            prev.addEventListener('click', function () {
                rendition.prev();
            });
            next.addEventListener('click', function () {
                rendition.next();
            });
            onKeys(function () {
                rendition.prev();
            }, function () {
                rendition.next();
            });
            onGoto(function (position) {
                rendition.display(position);
            });

            // Percentages need a location index, which is expensive; build it
            // in the background so the first page is not held up by it.
            book.ready.then(function () {
                return book.locations.generate(1600);
            }).catch(function () {
                // Without it the reader still works, just without percentages.
            });
        }).catch(function (error) {
            fail('This EPUB could not be opened: ' + error.message);
        });
    }

    /* Plain text ------------------------------------------------------ */

    function startText() {
        fetch(root.dataset.file, { credentials: 'same-origin' }).then(function (response) {
            return response.text();
        }).then(function (text) {
            var pre = document.createElement('pre');
            pre.className = 'reader__text';
            pre.textContent = text;

            if (status) {
                status.remove();
            }

            viewport.appendChild(pre);

            var saved = parseInt(root.dataset.position, 10);

            if (saved > 0) {
                viewport.scrollTop = (saved / 100) * viewport.scrollHeight;
            }

            viewport.addEventListener('scroll', function () {
                var percent = viewport.scrollHeight <= viewport.clientHeight
                    ? 100
                    : (viewport.scrollTop / (viewport.scrollHeight - viewport.clientHeight)) * 100;

                said(Math.round(percent) + '%');
                at(String(Math.round(percent)), percent);
            });

            said('0%');
        }).catch(function (error) {
            fail('This file could not be read: ' + error.message);
        });
    }

    if (root.dataset.kind === 'pdf') {
        startPdf();
    } else if (root.dataset.kind === 'epub') {
        startEpub();
    } else {
        startText();
    }
})();
