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
    static values = { geojsonUrl: String };

    connect() {
        this._onConnect = (event) => {
            this.map = event.detail.map;
            this.layer = L.geoJSON(null, { pointToLayer: (feature, latlng) => this.pointToLayer(feature, latlng) }).addTo(this.map);
            this.load();
            this.map.on('zoomend', () => this.load());
        };

        this.element.addEventListener('ux:map:connect', this._onConnect);
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this._onConnect);
    }

    pointToLayer(feature, latlng) {
        if (feature.properties.cluster) {
            return L.marker(latlng, {
                icon: L.divIcon({
                    html: `<div class="folio-map-marker-cluster">${feature.properties.point_count}</div>`,
                    className: '',
                    iconSize: [40, 40],
                }),
            });
        }

        const { label, thumbnailUrl, year } = feature.properties;
        const marker = L.marker(latlng);
        const thumb = thumbnailUrl ? `<img src="${thumbnailUrl}" alt="">` : '';
        marker.bindPopup(`<div class="folio-map-popup">${thumb}<strong>${label ?? ''}</strong>${year ? ` (${year})` : ''}</div>`);

        return marker;
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
            const bounds = this.layer.getBounds();
            if (bounds.isValid()) {
                this.map.fitBounds(bounds, { padding: [20, 20] });
            }
        }
    }
}
