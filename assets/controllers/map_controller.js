import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

// leaflet.markercluster is a UMD plugin that extends the GLOBAL L (L.MarkerClusterGroup = ...)
// -- it has no exports of its own, just this side effect, so `window.L` has to already be the
// same object this module's own `import L from 'leaflet'` binding refers to before the plugin
// file runs. AssetMapper's module graph gives every importer of 'leaflet' the same singleton
// instance, so this assignment (done once, importmap.php pins both to raw dist files rather
// than jsDelivr's "+esm" auto-bundler -- see that file's own comment on why) is enough to make
// L.MarkerClusterGroup below resolve correctly.
window.L = L;
import 'leaflet.markercluster';
import 'leaflet.markercluster/MarkerCluster.css';
import 'leaflet.markercluster/MarkerCluster.Default.css';

/*
 * Fetches raw (un-clustered) points from map.geojson -- see FolioController::mapGeoJson()'s own
 * comment on why clustering moved entirely client-side -- and lets Leaflet.markercluster group
 * them visually, the same real radius-based algorithm class fortepan.hu's own map uses (Google
 * Maps + @googlemaps/markerclusterer's SuperClusterAlgorithm). Re-fetches on every bounds change
 * (pan OR zoom, not just zoom -- the geojson endpoint is now bbox-filtered, so panning genuinely
 * needs new data, not just a different rounding precision on the same dataset).
 */
export default class extends Controller {
    static values = { geojsonUrl: String, rowUrlTemplate: String, slideshowBaseUrl: String };

    // __LOCAL_ID__ placeholder convention shared with PhotoGrid's rowUrlTemplate (server-side
    // Twig can't reach into this JS-side substitution, so both sides just agree on the token).
    rowUrl(id) {
        return this.rowUrlTemplateValue ? this.rowUrlTemplateValue.replace('__LOCAL_ID__', encodeURIComponent(id)) : null;
    }

    connect() {
        this._onConnect = (event) => {
            this.map = event.detail.map;
            this.clusterGroup = L.markerClusterGroup({ maxClusterRadius: 80, chunkedLoading: true });
            this.map.addLayer(this.clusterGroup);
            this.load();
            this._onMoveEnd = () => this.load();
            this.map.on('moveend', this._onMoveEnd);
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
        this.map?.off('moveend', this._onMoveEnd);
    }

    // Dataset text (label/city) is untrusted content from the source archive, not markup this app
    // authored -- escape it before it lands in a Leaflet popup's innerHTML.
    escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[char]);
    }

    popupHtml({ id, label, city, thumbnailUrl, year }) {
        const thumb = thumbnailUrl ? `<img src="${thumbnailUrl}" alt="">` : '';
        const caption = `<strong>${this.escapeHtml(label)}</strong>${year ? ` (${year})` : ''}`;
        const place = city ? `<div class="folio-map-popup-place">${this.escapeHtml(city)}</div>` : '';
        const url = this.rowUrl(id);
        const body = `${thumb}${caption}${place}`;

        return `<div class="folio-map-popup">${url ? `<a href="${url}">${body}</a>` : body}</div>`;
    }

    markerFor(feature) {
        const [lng, lat] = feature.geometry.coordinates;
        const { id, label, city, thumbnailUrl, year } = feature.properties;

        // A themeable div (CSS-only: color/size/shape all come from map.html.twig's
        // --folio-map-* custom properties) instead of Leaflet's default raster pin -- a designer
        // can restyle it without touching this JS at all.
        const marker = L.marker([lat, lng], {
            icon: L.divIcon({ html: '<div class="folio-map-marker"></div>', className: '', iconSize: [16, 16] }),
        });
        marker.bindPopup(this.popupHtml({ id, label, city, thumbnailUrl, year }));

        return marker;
    }

    async load() {
        const bounds = this.map.getBounds();
        const bbox = [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].map((n) => n.toFixed(5)).join(',');
        const zoom = Math.round(this.map.getZoom());

        const response = await fetch(`${this.geojsonUrlValue}/${zoom}?bbox=${bbox}`);
        const geojson = await response.json();

        this.clusterGroup.clearLayers();
        this.clusterGroup.addLayers(geojson.features.map((feature) => this.markerFor(feature)));

        // First load only: the initial center/zoom is an arbitrary placeholder (we don't know the
        // dataset's extent until the data arrives), so fit the view to what actually came back.
        // Once fit, later loads are pan/zoom-driven (moveend) and must NOT refit -- that would
        // fight the user's own navigation every time new points arrive.
        if (!this._hasFitBounds && geojson.features.length > 0) {
            this._hasFitBounds = true;
            const fitBounds = L.latLngBounds(geojson.features.map((f) => [f.geometry.coordinates[1], f.geometry.coordinates[0]]));
            if (fitBounds.isValid()) {
                this.map.fitBounds(fitBounds, { padding: [20, 20] });
            }
        }
    }
}
