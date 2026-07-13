import { Controller } from '@hotwired/stimulus';
// Registers <collection-slider> as a side effect. The element is the producer for the
// collection-slider-change event this controller consumes below -- see collection-slider.js
// for why it lives as a plain custom element rather than another Stimulus controller.
import '../elements/collection-slider.js';

/*
 * Lightweight thumbnail slideshow. To stay memory-safe over very large result sets,
 * the server passes only the ordered {id, thumb} list — NOT hydrated row data.
 * The main image shows the thumbnail; per-row detail is deferred for later.
 */
export default class extends Controller {
    static targets = ['image', 'index', 'detail', 'slider', 'thumbnail'];

    static values = {
        slides: { type: Array, default: [] },
        current: { type: Number, default: 0 },
    };

    connect() {
        // Value mode (numeric sort, e.g. Year): the slider runs over the real value range, so its value
        // is the year and we map it to the nearest slide. Index mode: the value is just the 1…N position.
        this._numeric = this.hasSliderTarget && this.sliderTarget.dataset.slideshowNumeric === '1';

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

        this._onSliderChange = (event) => {
            const value = Number(event.detail.value);
            this.show(this._numeric ? this.indexForValue(value) : value - 1);
        };

        window.addEventListener('keydown', this._onKeydown);

        if (this.hasSliderTarget) {
            this.sliderTarget.addEventListener('collection-slider-change', this._onSliderChange);
        }

        this.show(this.currentValue);
    }

    disconnect() {
        window.removeEventListener('keydown', this._onKeydown);

        if (this.hasSliderTarget) {
            this.sliderTarget.removeEventListener('collection-slider-change', this._onSliderChange);
        }
    }

    previous() {
        const count = this.slidesValue.length;

        if (count === 0) {
            return;
        }

        this.show((this.currentValue - 1 + count) % count);
    }

    next() {
        const count = this.slidesValue.length;

        if (count === 0) {
            return;
        }

        this.show((this.currentValue + 1) % count);
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

        if (this.hasSliderTarget) {
            this.sliderTarget.setAttribute('value', String(this._numeric ? slide.v : boundedIndex + 1));
        }

        this.setImage(slide, boundedIndex);

        if (this.hasIndexTarget) {
            this.indexTarget.textContent = `${boundedIndex + 1} / ${slides.length}`;
        }

        if (this.hasDetailTarget) {
            this.detailTarget.textContent = slide.id
                ? `@todo: details for row #${slide.id}`
                : `@todo: details for slide ${boundedIndex + 1}`;
        }

        this.thumbnailTargets.forEach((thumbnail) => {
            thumbnail.classList.toggle(
                'is-active',
                Number(thumbnail.dataset.slideIndex) === boundedIndex
            );
        });

        const activeThumbnail = this.thumbnailTargets[boundedIndex];

        activeThumbnail?.scrollIntoView({
            behavior: 'smooth',
            inline: 'center',
            block: 'nearest',
        });
    }

    // Nearest slide to a slider value (e.g. a year). Slides are ordered by the sort value, so a linear
    // scan for the smallest distance handles both ASC and DESC and ties to the first match.
    indexForValue(value) {
        const slides = this.slidesValue;
        let best = 0;
        let bestDiff = Infinity;

        for (let i = 0; i < slides.length; i++) {
            const diff = Math.abs(Number(slides[i].v) - value);

            if (diff < bestDiff) {
                bestDiff = diff;
                best = i;
            }
        }

        return best;
    }

    setImage(slide, index) {
        if (!this.hasImageTarget) {
            return;
        }

        this.imageTarget.classList.add('is-changing');

        window.setTimeout(() => {
            this.imageTarget.src = slide.thumb || '';
            this.imageTarget.alt = slide.id ? `#${slide.id}` : `Slide ${index + 1}`;

            this.imageTarget.onload = () => {
                this.imageTarget.classList.remove('is-changing');
            };
        }, 140);
    }
}