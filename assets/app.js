// Redirecter — Interaktion und Rückmeldung.
//
// Alles hier ist Zugabe: Ohne JavaScript funktionieren Anlegen und Löschen
// weiterhin über normale Formulare. Mit JavaScript kommt dazu, was Spaß macht.
// Jede Spielerei folgt einem UX-Prinzip; es steht jeweils im Kommentar.
(() => {
    'use strict';

    document.documentElement.classList.add('js');

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
    const $  = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    // Spielt eine CSS-Animation erneut ab, auch wenn die Klasse noch gesetzt ist.
    // Kinder-Animationen blubbern ebenfalls als animationend hoch — daher der
    // Blick auf e.target.
    const replay = (el, cls) => {
        if (!el || reduceMotion) return;
        el.classList.remove(cls);
        void el.offsetWidth;
        el.classList.add(cls);
        const done = (e) => {
            if (e.target !== el) return;
            el.classList.remove(cls);
            el.removeEventListener('animationend', done);
        };
        el.addEventListener('animationend', done);
    };

    // Haptik: ein kurzes Zucken auf dem Handy bestätigt, was man sieht.
    const buzz = (pattern) => {
        if (!reduceMotion && navigator.vibrate) navigator.vibrate(pattern);
    };

    const setCsrf = (token) => {
        $$('input[name="csrf_token"]').forEach((input) => { input.value = token; });
    };

    // ── Toasts ───────────────────────────────────────────────────────────────
    // Sichtbarkeit des Systemstatus: kurze Bestätigung, die nicht im Weg steht
    // und von selbst geht.
    const toastRegion = $('.toasts');

    function toast(text, opts = {}) {
        if (!toastRegion) return { dismiss() {} };

        const el = document.createElement('div');
        el.className = 'toast';
        const msg = document.createElement('span');
        msg.textContent = text;
        el.appendChild(msg);

        let closed = false;
        const duration = opts.duration ?? 2600;
        const dismiss = (reason) => {
            if (closed) return;
            closed = true;
            clearTimeout(timer);
            el.classList.remove('is-in');
            el.classList.add('is-out');
            setTimeout(() => el.remove(), 280);
            if (reason === 'timeout' && opts.onTimeout) opts.onTimeout();
        };

        if (opts.action) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'toast-action';
            btn.textContent = opts.action;
            if (opts.actionKey) {
                const k = document.createElement('kbd');
                k.className = 'kbd-hint';
                k.setAttribute('aria-hidden', 'true');
                k.textContent = opts.actionKey;
                btn.appendChild(k);
            }
            btn.addEventListener('click', () => {
                dismiss('action');
                if (opts.onAction) opts.onAction();
            });
            el.appendChild(btn);
        }

        // Ein schrumpfender Balken zeigt, wie lange man noch Zeit hat.
        if (opts.countdown) {
            const bar = document.createElement('span');
            bar.className = 'toast-bar';
            bar.style.animationDuration = duration + 'ms';
            el.appendChild(bar);
        }

        toastRegion.appendChild(el);
        requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-in')));
        const timer = setTimeout(() => dismiss('timeout'), duration);
        return { dismiss };
    }

    // ── Konfetti ─────────────────────────────────────────────────────────────
    // Peak-End-Regel: Der Erfolgsmoment ist der Höhepunkt, an den man sich
    // erinnert. Die Schnipsel sind 6 × 6 groß — dieselbe Marke, die jedes
    // nozilla-Icon unten rechts trägt.
    function confetti(origin, amount = 34) {
        if (reduceMotion || !origin || !Element.prototype.animate) return;

        const box = origin.getBoundingClientRect();
        const x0 = box.left + box.width / 2;
        const y0 = box.top + box.height / 2;
        const layer = document.createElement('div');
        layer.className = 'confetti';
        layer.setAttribute('aria-hidden', 'true');
        document.body.appendChild(layer);

        let longest = 0;
        for (let i = 0; i < amount; i++) {
            const bit = document.createElement('i');
            if (i % 3 === 0) bit.classList.add('k');
            if (i % 5 === 0) bit.classList.add('l');
            bit.style.left = x0 + 'px';
            bit.style.top = y0 + 'px';

            const angle = (-90 + (Math.random() * 150 - 75)) * Math.PI / 180;
            const speed = 140 + Math.random() * 260;
            const dx = Math.cos(angle) * speed;
            const dy = Math.sin(angle) * speed;
            const spin = (Math.random() < 0.5 ? -1 : 1) * (180 + Math.random() * 360);
            const duration = 900 + Math.random() * 600;
            longest = Math.max(longest, duration);

            bit.animate([
                { transform: 'translate(0px, 0px) rotate(0deg)', opacity: 1 },
                { transform: `translate(${dx * 0.65}px, ${dy * 0.65}px) rotate(${spin * 0.5}deg)`, opacity: 1, offset: 0.35 },
                { transform: `translate(${dx * 0.9}px, ${dy * 0.9 + 150}px) rotate(${spin * 0.85}deg)`, opacity: 1, offset: 0.8 },
                { transform: `translate(${dx}px, ${dy + 240}px) rotate(${spin}deg)`, opacity: 0 },
            ], { duration, easing: 'cubic-bezier(.2, .65, .35, 1)', fill: 'forwards' });

            layer.appendChild(bit);
        }
        setTimeout(() => layer.remove(), longest + 60);
    }

    // ── Zähler ───────────────────────────────────────────────────────────────
    // Fortschritt sichtbar machen: Jede neue Zahl rollt herein, aber nur die
    // Stellen, die sich wirklich ändern.
    function makeCounter(el) {
        if (!el) return null;
        const label = el.nextElementSibling && el.nextElementSibling.classList.contains('counter-label')
            ? el.nextElementSibling : null;
        let value = parseInt(el.dataset.count || el.textContent, 10) || 0;

        const render = (n, animate) => {
            const before = String(value);
            const after = String(n);
            el.textContent = '';
            [...after].forEach((ch, i) => {
                const d = document.createElement('span');
                d.className = 'd';
                d.textContent = ch;
                const fromRight = after.length - i;
                if (animate && before[before.length - fromRight] !== ch) d.classList.add('is-roll');
                el.appendChild(d);
            });
            if (label) label.textContent = n === 1 ? 'Kurzlink' : 'Kurzlinks';
            el.dataset.count = String(n);
            value = n;
        };

        render(value, false);
        return {
            get: () => value,
            set: (n) => { if (n !== value) render(Math.max(0, n), true); },
        };
    }

    // ── Kopieren ─────────────────────────────────────────────────────────────
    // Ein Klick zum Ziel: Wer einen Kurzlink macht, will ihn als Nächstes
    // irgendwo einfügen.
    async function copyText(text, btn) {
        let ok = false;
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); ok = true; } catch (e) { ok = false; }
        }
        if (!ok) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.className = 'visually-hidden';
            document.body.appendChild(ta);
            ta.select();
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            ta.remove();
        }

        if (btn) {
            const label = $('.copy-label', btn);
            btn.classList.add('is-done');
            replay(btn, 'is-pop');
            if (label) label.textContent = ok ? 'Kopiert ✓' : 'Bitte markieren';
            clearTimeout(btn.copyTimer);
            btn.copyTimer = setTimeout(() => {
                btn.classList.remove('is-done');
                if (label) label.textContent = 'Kopieren';
            }, 1800);
        }

        if (ok) {
            toast('Kopiert. Ab damit.');
            confetti(btn, 10);
            buzz(10);
        } else {
            toast('Kopieren ging nicht — bitte den Link markieren.', { duration: 4000 });
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.copy[data-copy]');
        if (btn) copyText(btn.dataset.copy, btn);
    });

    // ── QR-Code ──────────────────────────────────────────────────────────────
    // Erst beim Aufklappen laden — sonst erführe der QR-Dienst jeden erzeugten
    // Kurzlink, auch wenn ihn niemand sehen will.
    function initQr(scope) {
        const qr = $('.qr', scope);
        if (!qr) return;
        qr.addEventListener('toggle', () => {
            const img = $('img[data-qr]', qr);
            if (!qr.open || !img) return;
            img.addEventListener('error', () => {
                img.remove();
                const p = document.createElement('p');
                p.className = 'fallback';
                p.textContent = 'QR-Code nicht verfügbar (externer Dienst nicht erreichbar).';
                qr.appendChild(p);
            });
            img.src = img.dataset.qr;
            img.removeAttribute('data-qr');
        });
    }

    // ── Kurzlink anlegen ─────────────────────────────────────────────────────
    const createForm = $('form[data-base]');
    if (createForm) initCreate(createForm);

    function initCreate(form) {
        // Eigene Rückmeldung statt der Browser-Sprechblase
        form.noValidate = true;

        const url = $('#url', form);
        const slug = $('#slug', form);
        const urlStatus = $('#url-status', form);
        const slugStatus = $('#slug-status', form);
        const btn = $('.btn', form);
        const dice = $('.dice', form);
        const diceSvg = dice ? $('svg', dice) : null;
        const preview = $('.preview', form);
        const pvSlug = $('.preview-slug', form);
        const card = form.closest('.card');
        const slot = $('.result-slot');
        const tpl = $('#tpl-result');
        const counter = makeCounter($('.domain .counter'));
        const max = parseInt(form.dataset.max, 10) || 64;
        const demo = form.hasAttribute('data-demo');

        let busy = false;
        let urlWasValid = false;
        let shown = '';
        let noteTimer = null;

        if (dice) dice.hidden = false;
        if (preview) preview.hidden = false;
        const served = slot && $('.result-card', slot);
        if (served) {
            initQr(served);
            if (!reduceMotion) served.classList.add('is-in');
        }

        const setStatus = (box, kind, text) => {
            if (!box) return;
            box.textContent = '';
            if (!kind) return;
            const s = document.createElement('span');
            s.className = 'field-' + kind;
            s.textContent = text;
            box.appendChild(s);
        };

        const mark = (input, state) => {
            input.classList.toggle('is-valid', state === 'ok');
            input.classList.toggle('is-invalid', state === 'error');
            if (state === 'error') input.setAttribute('aria-invalid', 'true');
            else input.removeAttribute('aria-invalid');
        };

        // ── Ziel-URL ──
        const parseUrl = (raw) => {
            const v = raw.trim();
            if (!v) return { state: 'empty' };
            let u;
            try { u = new URL(v); } catch (e) { return { state: 'bad' }; }
            if (!/^https?:$/.test(u.protocol) || !u.hostname.includes('.')) return { state: 'bad' };
            return { state: 'ok', host: u.hostname.replace(/^www\./, '') };
        };

        // Fehlertoleranz: "example.com/x" ist erkennbar gemeint — wir ergänzen
        // https:// selbst, statt den Fehler zurückzugeben.
        const addScheme = () => {
            const v = url.value.trim();
            if (v && !/^[a-z][a-z0-9+.-]*:\/\//i.test(v) && /^[^\s/:]+\.[a-z]{2,}([/:?#]|$)/i.test(v)) {
                url.value = 'https://' + v;
                return true;
            }
            return false;
        };

        // Früh loben, spät meckern: Gültiges wird sofort bestätigt, Ungültiges
        // erst beim Verlassen des Feldes angemahnt — nicht bei jedem Tastendruck.
        const checkUrl = (fixed) => {
            const r = parseUrl(url.value);
            if (r.state === 'ok') {
                mark(url, 'ok');
                setStatus(urlStatus, 'ok', (fixed ? 'https:// ergänzt · ' : '') + r.host);
                if (!urlWasValid) {
                    replay(url, 'is-pop');
                    replay(btn, 'is-ready');   // Affordanz: der Knopf meldet sich bereit
                }
                urlWasValid = true;
            } else {
                mark(url, null);
                setStatus(urlStatus, null);
                urlWasValid = false;
            }
            return r;
        };

        url.addEventListener('input', (e) => {
            const fixed = e.inputType === 'insertFromPaste' && addScheme();
            checkUrl(fixed);
        });

        url.addEventListener('blur', () => {
            const fixed = addScheme();
            const r = checkUrl(fixed);
            if (r.state === 'bad') {
                mark(url, 'error');
                setStatus(urlStatus, 'error', 'Das ist noch keine vollständige Web-Adresse.');
            }
        });

        // ── Slug ──
        // "Kurz & gut" wird zu "Kurz-und-gut", nicht zu "Kurz--gut"
        const translit = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'Ä': 'Ae', 'Ö': 'Oe', 'Ü': 'Ue', 'ß': 'ss', '&': '-und-' };
        const cleanSlug = (v) => v
            .replace(/[äöüÄÖÜß&]/g, (c) => translit[c])
            .replace(/\s+/g, '-')
            .replace(/[^A-Za-z0-9_-]/g, '')
            .replace(/-{2,}/g, '-')
            .slice(0, max);

        // Sichtbarkeit: Man sieht den fertigen Link, während man tippt.
        // mode: 'none' baut still neu, 'grow' lässt neue Zeichen hereinfallen,
        // 'all' lässt alle Zeichen fallen (nach dem Würfeln).
        const renderPreview = (value, mode = 'none') => {
            if (!pvSlug) return;
            if (!value) {
                pvSlug.textContent = '';
                const ph = document.createElement('span');
                ph.className = 'ph';
                ph.textContent = 'wird ausgewürfelt';
                pvSlug.appendChild(ph);
                preview.classList.remove('is-changed');
                shown = '';
                return;
            }
            const grows = mode === 'grow' && shown !== '' && value.startsWith(shown) && value.length > shown.length;
            if (!grows) pvSlug.textContent = '';
            const from = grows ? shown.length : 0;
            [...value.slice(from)].forEach((ch, i) => {
                const span = document.createElement('span');
                span.className = 'ch';
                span.textContent = ch;
                if (!reduceMotion && (mode === 'all' || mode === 'grow')) {
                    span.classList.add('is-in');
                    if (mode === 'all') span.style.animationDelay = (i * 22) + 'ms';
                }
                pvSlug.appendChild(span);
            });
            preview.classList.add('is-changed');
            shown = value;
        };
        renderPreview(slug.value);

        // Fehler vermeiden statt melden: Umlaute und Leerzeichen werden beim
        // Tippen übersetzt, statt beim Absenden abgelehnt zu werden.
        slug.addEventListener('input', () => {
            const raw = slug.value;
            const clean = cleanSlug(raw);
            if (clean !== raw) {
                const caret = cleanSlug(raw.slice(0, slug.selectionStart ?? raw.length)).length;
                slug.value = clean;
                slug.setSelectionRange(caret, caret);
                replay(slug, 'is-fixed');
                mark(slug, null);
                setStatus(slugStatus, 'note', 'Angepasst: nur Buchstaben, Ziffern, - und _');
                clearTimeout(noteTimer);
                noteTimer = setTimeout(() => {
                    if ($('.field-note', slugStatus)) setStatus(slugStatus, null);
                }, 2400);
            } else if (slug.classList.contains('is-invalid')) {
                mark(slug, null);
                setStatus(slugStatus, null);
            }
            renderPreview(slug.value, 'grow');
        });

        // Spieltrieb mit Nutzen: Der Würfel macht aussprechbare Slugs
        // ("kamo-tibe") — die kann man sich merken und am Telefon diktieren.
        const consonants = 'bdfgklmnprstvz';
        const vowels = 'aeiou';
        const pick = (s) => s[Math.floor(Math.random() * s.length)];
        const syllables = (n) => Array.from({ length: n }, () => pick(consonants) + pick(vowels)).join('');
        // Zufall darf nicht peinlich werden: keine Doppelsilben ("kaka",
        // "pipi") und keine Wörter von dieser Liste.
        const awkward = /(..)\1|nazi|kot|tod/;
        const rollSlug = () => {
            let s;
            do { s = syllables(2) + '-' + syllables(2); } while (awkward.test(s.replace('-', '')));
            return s;
        };

        if (dice) {
            dice.addEventListener('click', () => {
                const final = rollSlug();
                replay(diceSvg, 'is-rolling');
                buzz(8);
                mark(slug, null);
                setStatus(slugStatus, null);

                if (reduceMotion) {
                    slug.value = final;
                    renderPreview(final);
                    return;
                }
                // Spielautomat: ein paar Würfe flackern durch, dann rastet er ein
                let frame = 0;
                const tick = () => {
                    if (frame++ < 7) {
                        const v = rollSlug();
                        slug.value = v;
                        renderPreview(v);
                        setTimeout(tick, 55);
                    } else {
                        slug.value = final;
                        renderPreview(final, 'all');
                        replay(slug, 'is-pop');
                    }
                };
                tick();
            });
        }

        // ── Absenden ──
        const send = async () => {
            const res = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        };

        // Vorschauseite ohne Server: antwortet, wie es der echte täte.
        let demoCount = null;
        const demoSend = async () => {
            await sleep(450);
            const s = slug.value;
            if (s === 'assets' || s === 'storage') {
                return { ok: false, error: 'Dieser Slug ist reserviert. Bitte einen anderen wählen.', field: 'slug' };
            }
            const random = Array.from({ length: 6 }, () => pick('abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789')).join('');
            demoCount = (demoCount ?? (counter ? counter.get() : 0)) + 1;
            return { ok: true, short: 'https://' + form.dataset.base + '/' + (s || random), count: demoCount };
        };

        const fail = (message, field) => {
            const input = field === 'slug' ? slug : (field === 'url' ? url : null);
            if (input) {
                mark(input, 'error');
                setStatus(input === url ? urlStatus : slugStatus, 'error', message);
                input.focus();
                input.select();
            } else {
                toast(message, { duration: 4200 });
            }
            replay(card, 'is-shake');
            buzz([10, 40, 10]);
        };

        const succeed = (data) => {
            const node = tpl.content.firstElementChild.cloneNode(true);
            const link = $('.result-link', node);
            link.href = data.short;
            link.textContent = data.short;
            $('.copy', node).dataset.copy = data.short;
            $('img[data-qr]', node).dataset.qr =
                'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' + encodeURIComponent(data.short);

            slot.replaceChildren(node);
            $$('.card > .alert-error').forEach((a) => a.remove());
            initQr(node);
            if (!reduceMotion) node.classList.add('is-in');

            confetti(node, 40);
            replay($('header .dot'), 'is-hop');
            buzz(18);
            if (counter && typeof data.count === 'number') counter.set(data.count);

            // Bereit für den nächsten — aber der Fokus liegt auf dem
            // naheliegenden nächsten Schritt: Kopieren.
            url.value = '';
            slug.value = '';
            mark(url, null);
            mark(slug, null);
            setStatus(urlStatus, null);
            setStatus(slugStatus, null);
            urlWasValid = false;
            renderPreview('');
            $('.copy', node).focus({ preventScroll: true });
            node.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
        };

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (busy) return;   // Fehlervermeidung: kein Doppel-Absenden

            const fixed = addScheme();
            const r = checkUrl(fixed);
            if (r.state !== 'ok') {
                fail(r.state === 'empty'
                    ? 'Wohin soll der Kurzlink führen? Die Ziel-URL fehlt noch.'
                    : 'Das ist noch keine vollständige Web-Adresse.', 'url');
                return;
            }

            busy = true;
            btn.classList.add('is-busy');
            btn.setAttribute('aria-busy', 'true');
            const started = performance.now();

            let data;
            try {
                data = demo ? await demoSend() : await send();
            } catch (e) {
                // Netz weg oder keine JSON-Antwort: auf den klassischen Weg ausweichen
                form.submit();
                return;
            }

            // Mindestens 350 ms Ladezustand — sonst flackert er nur kurz auf
            // und wirkt eher wie ein Fehler als wie Arbeit.
            const wait = 350 - (performance.now() - started);
            if (wait > 0) await sleep(wait);

            busy = false;
            btn.classList.remove('is-busy');
            btn.removeAttribute('aria-busy');
            if (data.csrf) setCsrf(data.csrf);

            if (data.ok) succeed(data);
            else fail(data.error || 'Das hat nicht geklappt.', data.field);
        });
    }

    // ── Verwaltung ───────────────────────────────────────────────────────────
    const table = $('table.links');
    let pendingDelete = null;
    let undoDelete = null;
    if (table) initManage(table);

    function initManage(table) {
        const tbody = $('tbody', table);
        const wrap = table.closest('.table-wrap');
        const counter = makeCounter($('.card-head .counter'));
        const filterRow = $('.filter-row');
        const filter = $('#filter');
        const empty = $('.empty:not(.empty-filter)');
        const emptyFilter = $('.empty-filter');

        // Eine Zeile mit .is-gone ist gelöscht oder wird es gerade — ob der
        // Server schon Bescheid weiß, spielt für die Anzeige keine Rolle.
        const liveRows = () => $$('tr[data-slug]', tbody).filter((r) => !r.classList.contains('is-gone'));

        if (filterRow) filterRow.hidden = false;
        $$('.btn-row.copy', table).forEach((b) => { b.hidden = false; });

        // Daten auf einen Blick: Balken unter den Klickzahlen, relativ zum
        // meistgeklickten Link. Sie wachsen beim Laden von null auf.
        const scaleBars = () => {
            const live = liveRows();
            const hits = live.map((r) => parseInt($('.col-hits', r).textContent, 10) || 0);
            const top = Math.max(1, ...hits);
            live.forEach((r, i) => $('.col-hits', r).style.setProperty('--p', String(hits[i] / top)));
        };
        requestAnimationFrame(() => requestAnimationFrame(scaleBars));

        // Wiedererkennen statt Erinnern: tippen, sehen, finden.
        const applyFilter = () => {
            const q = (filter ? filter.value : '').trim().toLowerCase();
            let visible = 0;
            $$('tr[data-slug]', tbody).forEach((r) => {
                if (r.classList.contains('is-gone')) { r.hidden = true; return; }
                const hit = !q
                    || r.dataset.slug.toLowerCase().includes(q)
                    || (r.dataset.target || '').toLowerCase().includes(q);
                r.hidden = !hit;
                if (hit) visible++;
            });
            if (emptyFilter) emptyFilter.hidden = !(q && visible === 0);
        };
        if (filter) filter.addEventListener('input', applyFilter);

        const refresh = () => {
            const any = liveRows().length > 0;
            applyFilter();
            if (empty) empty.hidden = any;
            if (wrap) wrap.hidden = !any;
            if (filterRow) filterRow.hidden = !any;
            if (!any && emptyFilter) emptyFilter.hidden = true;
            if (counter) counter.set(liveRows().length);
            scaleBars();
        };

        const bringBack = (row) => {
            row.classList.remove('is-gone', 'is-leaving');
            if (!reduceMotion) {
                row.classList.add('is-back');
                setTimeout(() => row.classList.remove('is-back'), 560);
            }
            refresh();
        };

        // Rückgängig statt Rückfrage: Ein Dialog vor jedem Löschen nervt und
        // wird weggeklickt, ohne gelesen zu werden. Besser: sofort ausführen
        // und fünf Sekunden lang ein Zurück anbieten.
        const commit = async (p) => {
            if (!p || p.done) return;
            p.done = true;
            if (pendingDelete === p) pendingDelete = null;
            p.toast.dismiss('commit');
            try {
                const res = await fetch(p.form.action, {
                    method: 'POST',
                    body: new FormData(p.form),
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (data.csrf) setCsrf(data.csrf);
                if (!data.ok) throw new Error(data.error || 'Löschen fehlgeschlagen.');
                p.row.remove();
                refresh();
            } catch (e) {
                bringBack(p.row);
                toast(e.message || 'Löschen fehlgeschlagen.', { duration: 4200 });
            }
        };

        const undo = (p) => {
            if (!p || p.done) return;
            p.done = true;
            if (pendingDelete === p) pendingDelete = null;
            p.toast.dismiss('undo');
            bringBack(p.row);
            toast(`„${p.slug}" ist wieder da.`);
        };
        undoDelete = () => undo(pendingDelete);

        $$('form.del-form', table).forEach((form) => {
            form.addEventListener('submit', (ev) => {
                ev.preventDefault();
                const row = form.closest('tr');
                if (row.classList.contains('is-gone')) return;
                if (pendingDelete) commit(pendingDelete);   // vorherigen sofort festschreiben

                const p = { row, form, slug: row.dataset.slug, done: false };
                pendingDelete = p;
                row.classList.add('is-gone');
                if (counter) counter.set(liveRows().length);
                buzz(10);

                // Erst rausrutschen lassen, dann ausblenden
                if (reduceMotion) refresh();
                else {
                    row.classList.add('is-leaving');
                    setTimeout(() => {
                        row.classList.remove('is-leaving');
                        if (row.classList.contains('is-gone')) refresh();
                    }, 240);
                }

                p.toast = toast(`„${p.slug}" gelöscht.`, {
                    action: 'Rückgängig',
                    actionKey: isMac ? '⌘Z' : 'Strg+Z',
                    duration: 5000,
                    countdown: true,
                    onAction: () => undo(p),
                    onTimeout: () => commit(p),
                });
            });
        });

        // Wer die Seite verlässt, während noch rückgängig gemacht werden könnte,
        // hat sich entschieden — also festschreiben.
        window.addEventListener('pagehide', () => {
            const p = pendingDelete;
            if (p && !p.done && navigator.sendBeacon) {
                p.done = true;
                navigator.sendBeacon(p.form.action, new FormData(p.form));
            }
        });
    }

    // ── Tastatur ─────────────────────────────────────────────────────────────
    // Abkürzungen für Geübte (Nielsens Heuristik 7), ohne Neulinge zu stören:
    //   /       ins Eingabe- bzw. Filterfeld
    //   ↵       Kurzlink erstellen (im Formular, wie gewohnt)
    //   C       Ergebnis kopieren
    //   Esc     Ergebnis schließen, neu anfangen
    //   ⌘/Strg+Z  Löschen rückgängig machen
    //   ?       Übersicht
    const typing = (el) => el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));

    document.addEventListener('keydown', (e) => {
        if (e.defaultPrevented || e.altKey) return;
        const mod = e.metaKey || e.ctrlKey;

        if (mod && e.key.toLowerCase() === 'z' && !typing(e.target) && pendingDelete && undoDelete) {
            e.preventDefault();
            undoDelete();
            return;
        }
        if (mod) return;

        if (typing(e.target)) {
            if (e.key === 'Escape') e.target.blur();
            return;
        }

        if (e.key === '/') {
            const field = $('#url') || $('#filter');
            if (field) { e.preventDefault(); field.focus(); field.select(); }
        } else if (e.key === 'c' || e.key === 'C') {
            const btn = $('.result-slot .copy');
            if (btn) { e.preventDefault(); btn.click(); }
        } else if (e.key === 'Escape') {
            const slot = $('.result-slot');
            if (slot && slot.firstElementChild) {
                slot.replaceChildren();
                const field = $('#url');
                if (field) field.focus();
            }
        } else if (e.key === '?') {
            toast(table
                ? 'Tasten: / Filtern · ' + (isMac ? '⌘Z' : 'Strg+Z') + ' Rückgängig'
                : 'Tasten: / Ziel-URL · ↵ Erstellen · C Kopieren · Esc Neu', { duration: 5000 });
        }
    });
})();
