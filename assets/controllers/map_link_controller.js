import { Controller } from '@hotwired/stimulus';

// Search publishes URL changes after each live facet update. Keep a real href for new tabs too.
export default class extends Controller {
    static values = { base: String, fixed: Object };

    connect() {
        this.onUpdate = (event) => this.update(event.detail.url);
        this.onClick = () => this.update(window.location.href);
        window.addEventListener('ux-search:url:update', this.onUpdate);
        this.element.addEventListener('click', this.onClick);
        this.update(window.location.href);
    }

    update(url) {
        const params = new URL(url, window.location.href).searchParams;
        for (const [key, value] of Object.entries(this.fixedValue)) params.set(key, value);
        const query = params.toString();
        // URL.search is percent-encoded ASCII, including non-Latin collection names.
        const token = btoa(query).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');
        this.element.href = this.baseValue + (token ? '/' + token : '');
    }

    disconnect() {
        window.removeEventListener('ux-search:url:update', this.onUpdate);
        this.element.removeEventListener('click', this.onClick);
    }
}
