// Kopier-Button und QR-Code. Beides rein progressiv: ohne JavaScript bleibt der
// Kurzlink markierbar und der QR-Bereich einfach zu.
(function () {
    'use strict';

    var copyBtn = document.querySelector('.copy');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var value = copyBtn.getAttribute('data-copy') || '';

            var done = function (label) {
                copyBtn.textContent = label;
                copyBtn.setAttribute('data-done', '');
                setTimeout(function () {
                    copyBtn.textContent = 'Kopieren';
                    copyBtn.removeAttribute('data-done');
                }, 2000);
            };

            // clipboard.writeText gibt es nur im sicheren Kontext (HTTPS bzw. localhost)
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(
                    function () { done('Kopiert'); },
                    function () { done('Fehlgeschlagen'); }
                );
                return;
            }

            var link = document.querySelector('.result a');
            if (link && window.getSelection) {
                var range = document.createRange();
                range.selectNodeContents(link);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                done('Markiert');
            }
        });
    }

    // Erst beim Aufklappen laden — sonst erführe der QR-Dienst jeden erzeugten
    // Kurzlink, auch wenn ihn niemand sehen will.
    var qr = document.querySelector('.qr');
    if (qr) {
        qr.addEventListener('toggle', function () {
            var img = qr.querySelector('img[data-qr]');
            if (!qr.open || !img) return;

            img.addEventListener('error', function () {
                img.remove();
                var p = document.createElement('p');
                p.className = 'fallback';
                p.textContent = 'QR-Code nicht verfügbar (externer Dienst nicht erreichbar).';
                qr.appendChild(p);
            });

            img.src = img.getAttribute('data-qr');
            img.removeAttribute('data-qr');
        });
    }
})();
