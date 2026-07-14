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

        this.observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) {
                this.loadMore();
            }
        });

        this.observer.observe(this.loaderTarget);
    }

    disconnect() {
        this.observer?.disconnect();
    }

    async loadMore() {
        if (this.loading || this.done || !this.endpointValue) {
            return;
        }

        this.loading = true;
        this.loaderTarget.hidden = false;

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
                return;
            }

            items.forEach((item) => this.appendItem(item));
            this.pageValue += 1;
        } finally {
            this.loading = false;
            this.loaderTarget.hidden = true;
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

        this.gridTarget.appendChild(a);
    }
}
