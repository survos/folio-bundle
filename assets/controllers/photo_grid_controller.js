import { Controller } from '@hotwired/stimulus';

/*
 * Infinite-scroll photo grid. The server renders page 1 (see PhotoGrid.php's
 * getItems()); this controller fetches subsequent pages from the same API
 * Platform endpoint (Row::API_ROWS) as the loader element scrolls into view,
 * appending thumbnails to the grid.
 *
 * `thumbnailUrl` is already a signed imgproxy URL by the time it reaches either code path --
 * FolioRowProvider::resolveThumbnails() resolves it server-side for every page (1 and beyond),
 * so both the server-rendered items and these client-fetched ones use it as-is, no JS-side
 * imgproxy call needed.
 */
export default class extends Controller {
    static targets = ['grid', 'loader', 'timeline'];

    static values = {
        endpoint: String,
        itemsPerPage: { type: Number, default: 50 },
        page: { type: Number, default: 2 },
        filters: { type: Object, default: {} },
        rowUrlTemplate: String,
        homeCountryCode: { type: String, default: '' },
    };

    connect() {
        this.loading = false;
        this.done = false;
        this.columns = [];
        this.nextColumn = 0;
        this.timelineYears = new Map();

        if (this.hasTimelineTarget) {
            // Scroll-spy: "which registered year-sentinel is nearest the top of the viewport".
            // rootMargin collapses the observed band to a thin strip near the top instead of the
            // whole viewport, so the highlighted tick tracks what's actually at the top edge as
            // you scroll, not just "anything visible". Created before _registerExistingYears()
            // below, which starts observing sentinels immediately.
            this.timelineObserver = new IntersectionObserver(
                (entries) => this._onTimelineIntersect(entries),
                { rootMargin: '-15% 0px -80% 0px' },
            );
        }

        this._layoutColumns();
        this._registerExistingYears();

        this._resizeObserver = new ResizeObserver(() => this._onResize());
        this._resizeObserver.observe(this.gridTarget);

        this.observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) {
                this.loadMore();
            }
        });

        this.observer.observe(this.loaderTarget);
    }

    disconnect() {
        this.observer?.disconnect();
        this._resizeObserver?.disconnect();
        this.timelineObserver?.disconnect();
    }

    // Round-robin item placement into a fixed number of flex columns, computed from the
    // grid's current width. Plain CSS multi-column (`columns:`) balances by total column
    // height instead -- it fills column 1 with the first N items, column 2 with the next N,
    // etc, so a year-sorted list stops *reading* sorted left-to-right even though the
    // underlying order is correct. Round-robin keeps reading order matching sort order.
    _targetColumnWidth() {
        return this.gridTarget.clientWidth <= 640 ? 128 : 192;
    }

    _columnCount() {
        return Math.max(1, Math.floor(this.gridTarget.clientWidth / this._targetColumnWidth()));
    }

    _layoutColumns() {
        const items = Array.from(this.gridTarget.querySelectorAll('.folio-photo-grid__item'));
        const numColumns = this._columnCount();

        this.gridTarget.innerHTML = '';
        this.columns = Array.from({ length: numColumns }, () => {
            const col = document.createElement('div');
            col.className = 'folio-photo-grid__column';
            this.gridTarget.appendChild(col);
            return col;
        });

        items.forEach((item, i) => this.columns[i % numColumns].appendChild(item));
        this.nextColumn = items.length % numColumns;
    }

    _onResize() {
        if (this._columnCount() !== this.columns.length) {
            this._layoutColumns();
        }
    }

    // Sticky year scrubber (Google Photos-style), built entirely from years already present in
    // the DOM -- SSR items carry data-year (see PhotoGrid.html.twig), client-fetched ones get it
    // set in appendItem() below. No "counts per year" endpoint: a tick appears the first time its
    // year is seen, so the scrubber only ever reflects what's actually loaded, and clicking a
    // tick can only jump to an already-loaded photo (scrollIntoView), never one further down that
    // hasn't been fetched yet.
    _registerExistingYears() {
        if (!this.hasTimelineTarget) {
            return;
        }

        this.gridTarget.querySelectorAll('.folio-photo-grid__item[data-year]').forEach((el) => {
            this._registerYear(el.dataset.year, el);
        });
    }

    _registerYear(year, el) {
        if (!this.hasTimelineTarget || !year) {
            return;
        }
        // dataset.year (SSR items) is always a string; item.dtoData.year (client-fetched, from
        // JSON) is a number -- normalize so both paths land in the same Map key, or the same
        // year gets a duplicate tick depending on which path registered it first.
        year = String(year);
        if (this.timelineYears.has(year)) {
            return;
        }

        const tick = document.createElement('button');
        tick.type = 'button';
        tick.className = 'folio-photo-grid__timeline-tick';
        tick.textContent = year;
        tick.addEventListener('click', () => el.scrollIntoView({ behavior: 'smooth', block: 'center' }));

        this.timelineTarget.appendChild(tick);
        this.timelineYears.set(year, tick);
        this.timelineObserver.observe(el);
    }

    _onTimelineIntersect(entries) {
        // Multiple sentinels can report in one batch; the topmost one still inside the observed
        // band (smallest boundingClientRect.top) is the one to highlight as "current".
        const visible = entries.filter((e) => e.isIntersecting);
        if (visible.length === 0) {
            return;
        }

        const topmost = visible.reduce((a, b) => (a.boundingClientRect.top <= b.boundingClientRect.top ? a : b));
        const year = topmost.target.dataset.year;

        this.timelineYears.forEach((tick, y) => tick.classList.toggle('is-active', y === year));
    }

    async loadMore() {
        if (this.loading || this.done || !this.endpointValue) {
            return;
        }

        this.loading = true;
        this.loaderTarget.classList.add('is-loading');

        const params = new URLSearchParams({
            ...this.filtersValue,
            page: this.pageValue,
            itemsPerPage: this.itemsPerPageValue,
        });

        try {
            const response = await fetch(`${this.endpointValue}?${params}`, {
                headers: { Accept: 'application/ld+json' },
            });
            const data = await response.json();
            const items = data.member ?? data['hydra:member'] ?? [];

            if (items.length === 0) {
                this.done = true;
                this.observer.disconnect();
                // Safe to actually remove from layout now -- nothing observes it anymore.
                this.loaderTarget.hidden = true;
                return;
            }

            items.forEach((item) => this.appendItem(item));
            this.pageValue += 1;
        } finally {
            this.loading = false;
            this.loaderTarget.classList.remove('is-loading');
        }
    }

    rowUrl(rp) {
        if (!rp || !this.hasRowUrlTemplateValue) {
            return '#';
        }

        const enc = (value) => encodeURIComponent(value ?? '');

        return this.rowUrlTemplateValue
            .replace('__PROVIDER__', enc(rp.provider))
            .replace('__DATASET__', enc(rp.dataset))
            .replace('__CORE_CODE__', enc(rp.coreCode))
            .replace('__DTO_TYPE__', enc(rp.dtoType))
            .replace('__LOCAL_ID__', enc(rp.localId));
    }

    // Same extras.place / dtoData fallback PhotoGrid.html.twig uses server-side, so
    // client-fetched pages (2+) get an identical caption to the SSR-rendered page 1.
    // isHomeCountry strips the trailing ", <country>" -- same "single-country folio, so
    // suppress the country that's true for almost every photo" rule as the flag suppression.
    place(item, isHomeCountry) {
        const raw = item.extras?.place
            || [item.dtoData?.city, item.dtoData?.state, item.dtoData?.country].filter(Boolean).join(', ');

        return isHomeCountry && item.dtoData?.country
            ? raw.replaceAll(`, ${item.dtoData.country}`, '')
            : raw;
    }

    isHomeCountry(item) {
        return Boolean(
            this.homeCountryCodeValue
                && item.countryFlagCode
                && item.countryFlagCode.toLowerCase() === this.homeCountryCodeValue.toLowerCase(),
        );
    }

    appendItem(item) {
        const a = document.createElement('a');
        a.className = 'folio-photo-grid__item';
        a.href = this.rowUrl(item.rp);
        a.title = item.label || '';

        if (item.thumbnailUrl) {
            const img = document.createElement('img');
            img.src = item.thumbnailUrl;
            img.alt = item.label || '';
            img.loading = 'lazy';
            img.decoding = 'async';
            a.appendChild(img);
        }

        const year = item.dtoData?.year;
        if (year) {
            a.dataset.year = year;
        }
        const isHomeCountry = this.isHomeCountry(item);
        const place = this.place(item, isHomeCountry);
        if (year || place) {
            const caption = document.createElement('div');
            caption.className = 'folio-photo-grid__caption';
            if (year) {
                const yearEl = document.createElement('span');
                yearEl.className = 'folio-photo-grid__caption-year';
                yearEl.textContent = year;
                caption.appendChild(yearEl);
            }
            if (place) {
                const placeEl = document.createElement('span');
                placeEl.className = 'folio-photo-grid__caption-place';
                // countryFlagCode is resolved server-side (Row::getCountryFlagCode(), via
                // Symfony Intl) so this stays a plain flag-icons span, no client-side
                // country-name lookup table to keep in sync with the PHP one.
                if (item.countryFlagCode && !isHomeCountry) {
                    const flag = document.createElement('span');
                    flag.className = `fi fi-${item.countryFlagCode.toLowerCase()}`;
                    placeEl.appendChild(flag);
                    placeEl.append(' ');
                }
                placeEl.append(place);
                caption.appendChild(placeEl);
            }
            a.appendChild(caption);
        }

        const column = this.columns[this.nextColumn % this.columns.length] ?? this.gridTarget;
        column.appendChild(a);
        this.nextColumn += 1;

        this._registerYear(year, a);
    }
}
