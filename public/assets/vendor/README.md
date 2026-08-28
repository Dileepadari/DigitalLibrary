# Vendored reader libraries

The Content-Security-Policy allows scripts from this origin only, and the
project has no build step, so the two reader libraries are committed here rather
than pulled from a CDN or a package manager at deploy time.

| File | Version | Licence | Used for |
|---|---|---|---|
| `pdf.min.mjs`, `pdf.worker.min.mjs` | PDF.js 4.6.82 | Apache-2.0 | rendering PDFs page by page |
| `epub.min.js` | epub.js 0.3.93 | BSD-2-Clause | rendering EPUBs |
| `jszip.min.js` | JSZip 3.10.1 | MIT | epub.js needs it to read the container |

To update one, replace the file and note the new version here. Nothing else
imports them: `public/assets/js/reader.js` is the only caller.
