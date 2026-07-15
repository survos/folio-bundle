import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

/*
 * Fetches the map.geojson feed (server-side clustered via a lat/lng rounding grid — see
 * FolioController::mapGeoJson()) and plots it on the Leaflet map ux-map just created. Cluster
 * features (properties.cluster === true) render as a plain count bubble; single features get a
 * normal marker with a popup (thumbnail + label). Re-fetches on every ux-map bounds/zoom change so
 * cluster granularity tracks the current zoom level.
 */
export default class extends Controller {
    static values = { geojsonUrl: String, rowUrlTemplate: String, slideshowBaseUrl: String };

    // __LOCAL_ID__ placeholder convention shared with PhotoGrid's rowUrlTemplate (server-side
    // Twig can't reach into this JS-side substitution, so both sides just agree on the token).
    rowUrl(id) {
        return this.rowUrlTemplateValue ? this.rowUrlTemplateValue.replace('__LOCAL_ID__', encodeURIComponent(id)) : null;
    }

    // A plain `?city=` query param (no filter token) -- FolioController's slideshowFilterParams()
    // reads req.query.all() as-is whenever no {filter} path segment is present, so this needs no
    // base64 encoding on this end to be understood as a city facet filter server-side.
    cityCollectionUrl(city) {
        return city && this.slideshowBaseUrlValue ? `${this.slideshowBaseUrlValue}?city=${encodeURIComponent(city)}` : null;
    }

    connect() {
        this._onConnect = (event) => {
            this.map = event.detail.map;
            this.layer = L.geoJSON(null, { pointToLayer: (feature, latlng) => this.pointToLayer(feature, latlng) }).addTo(this.map);
            this.load();
            this.map.on('zoomend', () => this.load());
        };

        // Both this controller and ux-map's own (symfony--ux-leaflet-map--map, on the SAME element)
        // are `fetch: "lazy"` -- independently dynamic-imported, with no guaranteed order. ux-map's
        // controller dispatches 'connect' synchronously as the last step of ITS OWN connect(), with
        // no buffering for listeners that attach after the fact. If its module resolves and connects
        // before this controller's lazy import finishes, the event fires into the void and is lost
        // forever -- this controller's addEventListener below never sees it, so load() never runs and
        // the map silently never fetches its geojson. Check synchronously for an already-connected
        // map first; only fall back to listening for the future event if it hasn't connected yet.
        const uxMap = this.application.getControllerForElementAndIdentifier(this.element, 'symfony--ux-leaflet-map--map');
        if (uxMap?.map) {
            this._onConnect({ detail: { map: uxMap.map } });
        } else {
            this.element.addEventListener('ux:map:connect', this._onConnect);
        }
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this._onConnect);
    }

    pointToLayer(feature, latlng) {
        if (feature.properties.cluster) {
            const marker = L.marker(latlng, {
                icon: L.divIcon({
                    html: `<div class="folio-map-marker-cluster">${feature.properties.point_count}</div>`,
                    className: '',
                    iconSize: [40, 40],
                }),
            });
            marker.on('click', () => this.openClusterPopup(marker, feature.properties));

            return marker;
        }

        const { id, label, city, thumbnailUrl, year } = feature.properties;
        // A themeable div (CSS-only: color/size/shape all come from map.html.twig's
        // --folio-map-* custom properties) instead of Leaflet's default raster pin -- a designer
        // can restyle it without touching this JS at all. See map.html.twig for the full set of
        // knobs and alternative shapes (square, ring, etc.).
        const marker = L.marker(latlng, {
            icon: L.divIcon({ html: '<div class="folio-map-marker"></div>', className: '', iconSize: [16, 16] }),
        });
        marker.bindPopup(this.popupHtml({ id, label, city, thumbnailUrl, year }));

        return marker;
    }

    // Dataset text (label/city) is untrusted content from the source archive, not markup this app
    // authored -- escape it before it lands in a Leaflet popup's innerHTML.
    escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[char]);
    }

    // Shared popup markup for both a lone marker and one slide of a cluster's mini-slideshow:
    // thumbnail + caption (label · year) + place, the whole thing a link to the photo's own
    // detail page (rowUrlTemplate) when one is configured, so the popup is a way IN, not a dead end.
    popupHtml({ id, label, city, thumbnailUrl, year }) {
        const thumb = thumbnailUrl ? `<img src="${thumbnailUrl}" alt="">` : '';
        const caption = `<strong>${this.escapeHtml(label)}</strong>${year ? ` (${year})` : ''}`;
        const place = city ? `<div class="folio-map-popup-place">${this.escapeHtml(city)}</div>` : '';
        const url = this.rowUrl(id);
        const body = `${thumb}${caption}${place}`;

        return `<div class="folio-map-popup">${url ? `<a href="${url}">${body}</a>` : body}</div>`;
    }

    // Fetches the cluster's individual rows once (cached per bucket+zoom) and shows them as an
    // in-place mini-slideshow in the marker's popup: one thumbnail at a time with prev/next arrows,
    // like fortepan.us/fortepan.hu's grouped map markers -- but paging is just an array-index change
    // (all items fetched upfront), no per-click round trip.
    async openClusterPopup(marker, { bucketLat, bucketLng, point_count: pointCount, city }) {
        const zoom = Math.round(this.map.getZoom());
        const key = `${zoom}:${bucketLat}:${bucketLng}`;
        this._clusterCache ??= new Map();

        if (!this._clusterCache.has(key)) {
            const response = await fetch(`${this.geojsonUrlValue}/${zoom}/at/${bucketLat}/${bucketLng}`);
            this._clusterCache.set(key, await response.json());
        }

        const items = this._clusterCache.get(key);
        if (!items.length) {
            return;
        }

        // City-level geocoding means every photo from the same place lands on the exact same point
        // -- no amount of zooming ever splits this cluster apart, unlike real per-photo GPS. Paging
        // through a mini-slideshow one at a time doesn't scale past a handful, so a uniformly-one-
        // city cluster (city !== null, see mapGeoJson()) gets a "View all" link to the full,
        // filtered collection instead -- a dynamic collection, not a dead-end popup.
        const collectionUrl = this.cityCollectionUrl(city);
        const collectionLink = collectionUrl
            ? `<a class="folio-map-cluster-view-all" href="${collectionUrl}">View all ${pointCount} in ${this.escapeHtml(city)}</a>`
            : '';

        let index = 0;
        const container = document.createElement('div');
        container.className = 'folio-map-cluster-slideshow';

        const render = () => {
            const item = items[index];
            const disabled = items.length <= 1 ? 'disabled' : '';
            const url = this.rowUrl(item.id);
            const openTag = url ? `<a href="${url}">` : '<div>';
            const closeTag = url ? '</a>' : '</div>';
            container.innerHTML = `
                <div class="folio-map-cluster-nav">
                    <button type="button" class="folio-map-cluster-prev" ${disabled}>&lsaquo;</button>
                    ${openTag}<img src="${item.thumbnailUrl ?? ''}" alt="">${closeTag}
                    <button type="button" class="folio-map-cluster-next" ${disabled}>&rsaquo;</button>
                </div>
                <div class="folio-map-cluster-caption">
                    ${openTag}${this.escapeHtml(item.label)}${item.year ? ` (${item.year})` : ''}${closeTag} &middot; ${index + 1}/${items.length}
                </div>
                ${item.city ? `<div class="folio-map-popup-place">${this.escapeHtml(item.city)}</div>` : ''}
                ${collectionLink}
            `;
            // Leaflet's Popup stops click propagation on its own container so map clicks (which close
            // popups by default) don't fire from clicks inside -- but that guard is installed once, on
            // the ORIGINAL container, and doesn't cover new markup swapped in via innerHTML on later
            // renders. Stop propagation explicitly on every render so prev/next never bubble up to the
            // map's closePopupOnClick handler (or the marker's own click-to-toggle listener).
            container.querySelector('.folio-map-cluster-prev')?.addEventListener('click', (event) => {
                L.DomEvent.stopPropagation(event);
                index = (index - 1 + items.length) % items.length;
                render();
            });
            container.querySelector('.folio-map-cluster-next')?.addEventListener('click', (event) => {
                L.DomEvent.stopPropagation(event);
                index = (index + 1) % items.length;
                render();
            });
        };
        render();

        marker.bindPopup(container).openPopup();
    }

    async load() {
        const zoom = Math.round(this.map.getZoom());
        const response = await fetch(`${this.geojsonUrlValue}/${zoom}`);
        const geojson = await response.json();

        this.layer.clearLayers();
        this.layer.addData(geojson);

        // First load only: the initial center/zoom is an arbitrary placeholder (we don't know the
        // dataset's extent until the data arrives), so fit the view to what actually came back.
        if (!this._hasFitBounds) {
            this._hasFitBounds = true;
            const bounds = this.majorityBounds(geojson.features);
            if (bounds) {
                this.map.fitBounds(bounds, { padding: [20, 20] });
            }
        }
    }

    // A handful of mis-geocoded photos on the other side of the world would otherwise drag
    // fitBounds() out to a whole-world view for a dataset that's really concentrated in one
    // region. Weight by point_count (features are already server-side clusters) and keep only
    // the largest clusters until they account for 90% of all plotted items -- outliers just end
    // up off-screen, which is the point.
    majorityBounds(features) {
        if (!features.length) {
            return null;
        }

        const countOf = (feature) => feature.properties.point_count ?? feature.properties.n ?? 1;
        const total = features.reduce((sum, feature) => sum + countOf(feature), 0);
        const sorted = [...features].sort((a, b) => countOf(b) - countOf(a));

        const majority = [];
        let running = 0;
        for (const feature of sorted) {
            majority.push(feature);
            running += countOf(feature);
            if (running >= total * 0.9) {
                break;
            }
        }

        const bounds = L.latLngBounds(majority.map((feature) => [feature.geometry.coordinates[1], feature.geometry.coordinates[0]]));

        return bounds.isValid() ? bounds : null;
    }
}
