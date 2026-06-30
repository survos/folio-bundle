import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['image', 'title', 'subtitle', 'index', 'summary', 'meta', 'detail'];

    static values = {
        slides: { type: Array, default: [] },
        current: { type: Number, default: 0 },
    };

    connect() {
        this._onKeydown = (event) => {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.previous();
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.next();
            }
        };
        window.addEventListener('keydown', this._onKeydown);
        this.show(this.currentValue);
    }

    disconnect() {
        window.removeEventListener('keydown', this._onKeydown);
    }

    previous() {
        if (this.slidesValue.length === 0) {
            return;
        }
        this.show((this.currentValue - 1 + this.slidesValue.length) % this.slidesValue.length);
    }

    next() {
        if (this.slidesValue.length === 0) {
            return;
        }
        this.show((this.currentValue + 1) % this.slidesValue.length);
    }

    jump(event) {
        const index = Number(event.currentTarget.dataset.slideIndex);
        if (Number.isInteger(index)) {
            this.show(index);
        }
    }

    show(index) {
        const slides = this.slidesValue;
        if (slides.length === 0) {
            return;
        }

        const boundedIndex = Math.max(0, Math.min(index, slides.length - 1));
        const slide = slides[boundedIndex];
        this.currentValue = boundedIndex;

        if (this.hasImageTarget) {
            this.imageTarget.src = slide.url || '';
            this.imageTarget.alt = slide.title || slide.id || `Slide ${boundedIndex + 1}`;
        }
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = slide.title || slide.id || 'Untitled';
        }
        if (this.hasSubtitleTarget) {
            const parts = [slide.coreCode, slide.dtoType, slide.id].filter(Boolean);
            this.subtitleTarget.textContent = parts.join(' / ');
        }
        if (this.hasIndexTarget) {
            this.indexTarget.textContent = `${boundedIndex + 1} / ${slides.length}`;
        }
        if (this.hasSummaryTarget) {
            this.summaryTarget.textContent = slide.summary || '';
            this.summaryTarget.hidden = !slide.summary;
        }
        if (this.hasDetailTarget) {
            this.detailTarget.href = slide.detailUrl || '#';
            this.detailTarget.hidden = !slide.detailUrl;
        }
        if (this.hasMetaTarget) {
            this.metaTarget.innerHTML = this.metadataHtml(slide.metadata || {});
        }

        this.element.querySelectorAll('[data-slide-index]').forEach((button) => {
            button.classList.toggle('active', Number(button.dataset.slideIndex) === boundedIndex);
        });
    }

    metadataHtml(metadata) {
        const rows = Object.entries(metadata).filter(([, value]) => value !== null && value !== undefined && value !== '');
        if (rows.length === 0) {
            return '<div class="text-muted small">No metadata available.</div>';
        }

        return rows.map(([key, value]) => {
            const label = this.escape(this.label(key));
            const rendered = key === 'citationUrl'
                ? `<a href="${this.escape(String(value))}" target="_blank" rel="noopener">${this.escape(String(value))}</a>`
                : this.escape(String(value));

            return `<div class="d-flex gap-3 border-bottom py-2">
                <dt class="text-muted small flex-shrink-0" style="width: 7rem;">${label}</dt>
                <dd class="mb-0 small text-break">${rendered}</dd>
            </div>`;
        }).join('');
    }

    label(key) {
        return key.replace(/([A-Z])/g, ' $1').replace(/^./, (char) => char.toUpperCase());
    }

    escape(value) {
        const span = document.createElement('span');
        span.textContent = value;
        return span.innerHTML;
    }
}
