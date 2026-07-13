/*
 * <collection-slider min max value step tick-count counts="{...}"></collection-slider>
 *
 * MIRROR — the canonical source is now the standalone package at ~/tacman/collection-slider
 * (published as @survos/collection-slider), extracted deliberately so this widget isn't tied to
 * folio/Symfony and can be installed with a trivial <script type="module"> or plain `npm install`
 * in any project. This copy exists only because Symfony AssetMapper resolves bundle JS straight
 * from `vendor/survos/folio-bundle/assets/` (a PHP-side vendor path, not `node_modules`), so a real
 * npm `file:`/registry dependency isn't cleanly resolvable here yet. Until that wiring exists,
 * keep this file byte-identical to the package's `src/collection-slider.js` by hand; once
 * @survos/collection-slider is actually published, replace this file with an import of the real
 * package instead of vendoring a copy.
 *
 * A self-registering, dependency-free custom element: a Fortepan.hu-style year slider (native
 * <input type="range"> for real keyboard/a11y support, wrapped in Shadow DOM so its styling can't
 * collide with Tabler or anything else the host page loads) that also drives a fortepan.us-style
 * live-following thumbnail strip. Dragging dispatches `collection-slider-change` (bubbling,
 * composed, detail: {value, min, max, percent}) on every whole-value change while dragging -- the
 * exact event slideshow_controller.js already listens for (see slideshow.html.twig). No changes
 * needed there: this element is the missing producer for an event contract that already existed.
 *
 * History: this started as a more complete, orphaned prototype at zm's own `assets/collection-
 * slider.js` -- never wired into any importmap entry or template there. Ported into folio-bundle
 * first (needed to be shared between zm/Museado and openfoto), then extracted again into the
 * standalone package above once it was clear the widget itself has zero folio-specific logic.
 * Two things added on top of the original zm prototype:
 *   - `counts`: a value -> count map for the pill labels (Fortepan.hu's loved "little black number
 *     that hovers above the red timeline marker" -- the zm prototype had no such feature).
 *   - live `collection-slider-change` dispatch on every value change *during* drag, not just on
 *     pointerup -- the zm prototype only fired 'change' on release (plus a separate 'input' event
 *     during drag that nothing here listens for), which would make the thumbnail strip jump only at
 *     the end of a drag rather than glide with it the way fortepan.us/kronofoto's does.
 */
class CollectionSlider extends HTMLElement {
    static get observedAttributes() {
        return ['min', 'max', 'value', 'step', 'tick-count', 'counts'];
    }

    constructor() {
        super();
        this.attachShadow({ mode: 'open' });

        this.shadowRoot.innerHTML = `
            <style>
                :host {
                    display: block;
                    padding-top: 36px;

                    --collection-slider-height: 58px;
                    --collection-slider-center: calc(var(--collection-slider-height) / 2);
                    --collection-slider-pill-width: 64px;
                    --collection-slider-pill-height: 32px;
                    --collection-slider-pill-radius: 9999px;
                    --collection-slider-pill-font-size: 14px;
                    --collection-slider-pill-font-weight: 700;
                    --collection-slider-rail-offset: calc(var(--collection-slider-pill-width) * 1.5);

                    --collection-slider-track-height: 2px;
                    --collection-slider-track-color: #c9c9c9;
                    --collection-slider-track-active-color: #c0392b;

                    --collection-slider-tick-width: 2px;
                    --collection-slider-tick-height: 20px;
                    --collection-slider-tick-color: #c9c9c9;

                    --collection-slider-input-height: 36px;

                    --collection-slider-pill-background: #e5e5e5;
                    --collection-slider-pill-color: #333;

                    --collection-slider-current-pill-background: #c0392b;
                    --collection-slider-current-pill-color: #fff;

                    --collection-slider-hover-pill-background: #111;
                    --collection-slider-hover-pill-color: #fff;
                }

                .slider {
                    position: relative;
                    height: var(--collection-slider-height);
                    overflow: visible;
                    user-select: none;
                    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }

                .rail {
                    position: absolute;
                    top: 0;
                    left: var(--collection-slider-rail-offset);
                    right: var(--collection-slider-rail-offset);
                    height: 100%;
                }

                .track,
                .active-track {
                    position: absolute;
                    top: calc(var(--collection-slider-center) - (var(--collection-slider-track-height) / 2));
                    height: var(--collection-slider-track-height);
                }

                .track {
                    left: calc(var(--collection-slider-pill-width) / -2);
                    right: calc(var(--collection-slider-pill-width) / -2);
                    background: var(--collection-slider-track-color);
                }

                .active-track {
                    left: calc(var(--collection-slider-pill-width) / -2);
                    width: 0%;
                    background: var(--collection-slider-track-active-color);
                }

                .ticks {
                    position: absolute;
                    top: calc(var(--collection-slider-center) - (var(--collection-slider-tick-height) / 2));
                    left: 0;
                    right: 0;
                    height: var(--collection-slider-tick-height);
                    pointer-events: none;
                }

                .tick {
                    position: absolute;
                    top: 0;
                    width: var(--collection-slider-tick-width);
                    height: var(--collection-slider-tick-height);
                    background: var(--collection-slider-tick-color);
                    transform: translateX(-50%);
                }

                .hit-area {
                    position: absolute;
                    inset: 0;
                    cursor: pointer;
                    z-index: 20;
                }

                input[type="range"] {
                    position: absolute;
                    top: calc(var(--collection-slider-center) - (var(--collection-slider-input-height) / 2));
                    left: 0;
                    right: 0;
                    width: 100%;
                    height: var(--collection-slider-input-height);
                    margin: 0;
                    opacity: 0;
                    pointer-events: none;
                }

                .pill {
                    position: absolute;
                    top: calc(var(--collection-slider-center) - (var(--collection-slider-pill-height) / 2));
                    width: var(--collection-slider-pill-width);
                    height: var(--collection-slider-pill-height);
                    padding: 0 12px;
                    border-radius: var(--collection-slider-pill-radius);
                    font-size: var(--collection-slider-pill-font-size);
                    font-weight: var(--collection-slider-pill-font-weight);
                    line-height: 1;
                    text-align: center;
                    box-sizing: border-box;
                    pointer-events: none;
                    z-index: 5;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    white-space: nowrap;
                }

                .pill--min {
                    left: 0;
                    background: var(--collection-slider-pill-background);
                    color: var(--collection-slider-pill-color);
                }

                .pill--max {
                    right: 0;
                    background: var(--collection-slider-pill-background);
                    color: var(--collection-slider-pill-color);
                }

                .pill--current {
                    left: 0%;
                    width: auto;
                    min-width: var(--collection-slider-pill-width);
                    padding: 0 14px;
                    transform: translateX(-50%);
                    background: var(--collection-slider-current-pill-background);
                    color: var(--collection-slider-current-pill-color);
                    z-index: 15;
                }

                .pill--hover {
                    top: calc(var(--collection-slider-center) - var(--collection-slider-pill-height) - 10px);
                    left: 0%;
                    width: auto;
                    min-width: 44px;
                    height: 30px;
                    padding: 0 8px;
                    transform: translateX(-50%);
                    border-radius: 4px;
                    background: var(--collection-slider-hover-pill-background);
                    color: var(--collection-slider-hover-pill-color);
                    font-size: 13px;
                    font-weight: 700;
                    z-index: 30;
                    opacity: 0;
                }

                .pill--hover::after {
                    content: "";
                    position: absolute;
                    left: 50%;
                    bottom: -6px;
                    transform: translateX(-50%);
                    border-left: 6px solid transparent;
                    border-right: 6px solid transparent;
                    border-top: 6px solid var(--collection-slider-hover-pill-background);
                }

                .pill--hover.is-visible {
                    opacity: 1;
                }
            </style>

            <div class="slider">
                <div class="pill pill--min"></div>
                <div class="pill pill--max"></div>

                <div class="rail">
                    <div class="track"></div>
                    <div class="active-track"></div>
                    <div class="ticks"></div>
                    <div class="pill pill--current"></div>
                    <div class="pill pill--hover"></div>
                    <div class="hit-area"></div>
                    <input type="range">
                </div>
            </div>
        `;

        this.rail = this.shadowRoot.querySelector('.rail');
        this.hitArea = this.shadowRoot.querySelector('.hit-area');
        this.input = this.shadowRoot.querySelector('input');
        this.ticks = this.shadowRoot.querySelector('.ticks');
        this.activeTrack = this.shadowRoot.querySelector('.active-track');
        this.minPill = this.shadowRoot.querySelector('.pill--min');
        this.maxPill = this.shadowRoot.querySelector('.pill--max');
        this.currentPill = this.shadowRoot.querySelector('.pill--current');
        this.hoverPill = this.shadowRoot.querySelector('.pill--hover');

        this._counts = new Map();
        this._lastDispatchedValue = null;
    }

    connectedCallback() {
        this.setup();
        this.bindEvents();
        this.render();
    }

    attributeChangedCallback(name) {
        if (name === 'counts') {
            this._counts = this._parseCounts();
        }
        if (!this.input) return;
        this.setup();
        this.render();
    }

    // Public property API -- lets a Stimulus controller do `sliderEl.counts = {...}` once counts
    // come back from a fetch, without round-tripping through a JSON attribute.
    get counts() {
        return this._counts;
    }

    set counts(map) {
        this._counts = map instanceof Map ? map : new Map(Object.entries(map ?? {}).map(([v, n]) => [String(v), Number(n)]));
        this.render();
    }

    _parseCounts() {
        const raw = this.getAttribute('counts');
        if (!raw) {
            return new Map();
        }
        try {
            return new Map(Object.entries(JSON.parse(raw)).map(([v, n]) => [String(v), Number(n)]));
        } catch {
            return new Map();
        }
    }

    countLabel(value) {
        const count = this._counts.get(String(Math.round(Number(value))));
        return count === undefined ? String(value) : `${value} · ${count}`;
    }

    setup() {
        const min = this.getAttribute('min') || 0;
        const max = this.getAttribute('max') || 100;
        const step = this.getAttribute('step') || 1;
        const value = this.getAttribute('value') || min;

        this.input.min = min;
        this.input.max = max;
        this.input.step = step;
        this.input.value = value;

        this.minPill.textContent = min;
        this.maxPill.textContent = max;

        this.renderTicks();
    }

    bindEvents() {
        if (this.bound) return;
        this.bound = true;

        this.hitArea.addEventListener('pointermove', (event) => {
            this.showHover(event);

            if (event.buttons === 1) {
                this.updateFromPointer(event, true);
            }
        });

        this.hitArea.addEventListener('pointerleave', () => {
            this.hoverPill.classList.remove('is-visible');
        });

        this.hitArea.addEventListener('pointerdown', (event) => {
            this.hitArea.setPointerCapture(event.pointerId);
            this.updateFromPointer(event, true);
        });

        this.hitArea.addEventListener('pointerup', (event) => {
            this.hitArea.releasePointerCapture(event.pointerId);
            this.dispatchChange();
        });

        this.input.addEventListener('keydown', () => {
            requestAnimationFrame(() => {
                this.setAttribute('value', this.input.value);
                this.render();
                this.dispatchChange();
            });
        });
    }

    valueFromPointer(event) {
        const rect = this.rail.getBoundingClientRect();
        const x = Math.min(Math.max(event.clientX - rect.left, 0), rect.width);
        const percent = rect.width === 0 ? 0 : x / rect.width;

        const min = Number(this.input.min);
        const max = Number(this.input.max);
        const step = Number(this.input.step) || 1;

        const rawValue = min + percent * (max - min);
        const value = Math.round((rawValue - min) / step) * step + min;

        return Math.min(Math.max(value, min), max);
    }

    updateFromPointer(event, emitInput = false) {
        const value = this.valueFromPointer(event);

        this.input.value = value;
        this.setAttribute('value', value);
        this.render();

        if (emitInput) {
            this.dispatchEvent(new CustomEvent('collection-slider-input', {
                bubbles: true,
                composed: true,
                detail: this.detail
            }));

            // Live-follow while dragging (fortepan.us/kronofoto's behaviour): dispatch the same
            // 'change' event slideshow_controller.js listens for on every whole-value change, not
            // only on pointerup, so the thumbnail strip glides with the knob instead of jumping
            // once at the end of the drag.
            this.dispatchChange();
        }
    }

    dispatchChange() {
        const value = Number(this.input.value);
        if (value === this._lastDispatchedValue) {
            return;
        }
        this._lastDispatchedValue = value;

        this.dispatchEvent(new CustomEvent('collection-slider-change', {
            bubbles: true,
            composed: true,
            detail: this.detail
        }));
    }

    isPointerOverCurrentPill(event) {
        const rect = this.currentPill.getBoundingClientRect();

        return (
            event.clientX >= rect.left &&
            event.clientX <= rect.right &&
            event.clientY >= rect.top &&
            event.clientY <= rect.bottom
        );
    }

    showHover(event) {
        if (this.isPointerOverCurrentPill(event)) {
            this.hoverPill.classList.remove('is-visible');
            return;
        }

        const value = this.valueFromPointer(event);
        const percent = this.percentFromValue(value);

        this.hoverPill.style.left = `${percent}%`;
        this.hoverPill.textContent = this.countLabel(value);
        this.hoverPill.classList.add('is-visible');
    }

    render() {
        const percent = this.percent;

        this.currentPill.style.left = `${percent}%`;
        this.currentPill.textContent = this.countLabel(this.input.value);

        this.activeTrack.style.width = `calc((var(--collection-slider-pill-width) / 2) + ${percent}%)`;
    }

    renderTicks() {
        const min = Number(this.input.min);
        const max = Number(this.input.max);
        const totalValues = max - min + 1;

        this.ticks.innerHTML = '';

        if (max <= min) {
            return;
        }

        const tickCount = Math.min(totalValues, Number(this.getAttribute('tick-count') || 10));

        for (let i = 0; i < tickCount; i++) {
            const tick = document.createElement('span');

            const percent = tickCount === 1
                ? 0
                : (i / (tickCount - 1)) * 100;

            tick.className = 'tick';
            tick.style.left = `${percent}%`;

            this.ticks.appendChild(tick);
        }
    }

    percentFromValue(value) {
        const min = Number(this.input.min);
        const max = Number(this.input.max);

        if (max === min) return 0;

        return ((Number(value) - min) / (max - min)) * 100;
    }

    get percent() {
        return this.percentFromValue(this.input.value);
    }

    get detail() {
        return {
            value: Number(this.input.value),
            min: Number(this.input.min),
            max: Number(this.input.max),
            percent: this.percent
        };
    }
}

if (!customElements.get('collection-slider')) {
    customElements.define('collection-slider', CollectionSlider);
}
