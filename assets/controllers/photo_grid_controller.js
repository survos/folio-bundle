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
    static targets = ['grid', 'loader'];

    static values = {
        endpoint: String,
        itemsPerPage: { type: Number, default: 50 },
        page: { type: Number, default: 2 },
        filters: { type: Object, default: {} },
        rowUrlTemplate: String,
    };

    connect() {
        this.loading = false;
        this.done = false;
        this.columns = [];
        this.nextColumn = 0;

        this._layoutColumns();

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
    place(item) {
        if (item.extras?.place) {
            return item.extras.place;
        }
        return [item.dtoData?.city, item.dtoData?.state, item.dtoData?.country].filter(Boolean).join(', ');
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
        const place = this.place(item);
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
                if (item.countryFlagCode) {
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
    }
}
