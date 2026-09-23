import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        highlightMode: { type: String, default: 'word' },
    };

    connect() {
        this.audio = this.element.previousElementSibling;
        this.words = [...this.element.querySelectorAll('[data-sync-word]')];
        this.utterances = [...this.element.querySelectorAll('[data-sync-segment]')];

        if (!(this.audio instanceof HTMLAudioElement) || this.utterances.length === 0) {
            return;
        }

        if (!['word', 'sentence', 'utterance'].includes(this.highlightModeValue)) {
            throw new Error(`Unknown transcript highlight mode: ${this.highlightModeValue}`);
        }

        this.units = this.makeUnits();
        this.active = null;
        this.audioPlaceholder = null;
        this.audioContainer = null;
        this.visibilityObserver = null;
        this.observedAnchor = null;
        this.originalAudioStyle = this.audio.style.cssText;
        this.lastManualScrollAt = 0;
        this.unitByElement = new WeakMap();
        this.abortController = new AbortController();
        this.sync = this.sync.bind(this);
        this.observeAudioVisibility = this.observeAudioVisibility.bind(this);
        this.floatAudio = this.floatAudio.bind(this);
        this.restoreAudio = this.restoreAudio.bind(this);
        this.seek = this.seek.bind(this);
        this.noteManualScroll = this.noteManualScroll.bind(this);
        this.noteScrollKey = this.noteScrollKey.bind(this);

        const listenerOptions = { signal: this.abortController.signal };
        this.audio.addEventListener('timeupdate', this.sync, listenerOptions);
        this.audio.addEventListener('seeked', this.sync, listenerOptions);
        // NOT float-on-play: the player used to jump into the floating pill the moment you
        // pressed play, even while you were looking straight at it -- the controls appeared to
        // vanish (2026-09-23). It floats only once it has actually scrolled out of view, and
        // comes back when it scrolls in.
        this.audio.addEventListener('play', this.observeAudioVisibility, listenerOptions);
        this.audio.addEventListener('ended', this.restoreAudio, listenerOptions);
        this.element.addEventListener('click', this.seek, listenerOptions);
        window.addEventListener('wheel', this.noteManualScroll, { ...listenerOptions, passive: true });
        window.addEventListener('touchmove', this.noteManualScroll, { ...listenerOptions, passive: true });
        window.addEventListener('keydown', this.noteScrollKey, listenerOptions);

        this.units.forEach((unit) => unit.elements.forEach((element) => {
            element.style.cursor = 'pointer';
            this.unitByElement.set(element, unit);
        }));
    }

    disconnect() {
        if (!(this.audio instanceof HTMLAudioElement)) {
            return;
        }

        this.abortController?.abort();
        this.visibilityObserver?.disconnect();
        this.visibilityObserver = null;
        this.observedAnchor = null;
        this.units?.forEach((unit) => unit.elements.forEach((element) => {
            element.style.cursor = '';
        }));
        this.active?.elements.forEach((element) => element.classList.remove('bg-yellow-lt', 'rounded'));
        this.restoreAudio();
    }

    makeUnits() {
        if (this.highlightModeValue === 'utterance' || this.words.length === 0) {
            return this.utterances.map((utterance) => this.makeUnit([utterance]));
        }

        if (this.highlightModeValue === 'word') {
            return this.words.map((word) => this.makeUnit([word]));
        }

        const units = [];

        this.utterances.forEach((utterance) => {
            let sentence = [];

            utterance.querySelectorAll('[data-sync-word]').forEach((word) => {
                sentence.push(word);
                if (!/[.!?]["'”’)]?$/.test(word.textContent.trim())) {
                    return;
                }

                units.push(this.makeUnit(sentence));
                sentence = [];
            });

            if (sentence.length > 0) {
                units.push(this.makeUnit(sentence));
            }
        });

        return units;
    }

    makeUnit(elements) {
        return {
            elements,
            startMs: Number(elements[0].dataset.startMs),
            endMs: Number(elements[elements.length - 1].dataset.endMs),
        };
    }

    sync() {
        const currentMs = this.audio.currentTime * 1000;
        const next = this.units.find((unit) => currentMs >= unit.startMs && currentMs < unit.endMs) ?? null;

        if (next === this.active) {
            return;
        }

        this.active?.elements.forEach((element) => element.classList.remove('bg-yellow-lt', 'rounded'));
        next?.elements.forEach((element) => element.classList.add('bg-yellow-lt', 'rounded'));
        this.active = next;

        if (next && !this.audio.paused && Date.now() - this.lastManualScrollAt >= 30000) {
            const scrollTarget = next.elements[0];
            const bounds = scrollTarget.getBoundingClientRect();
            if (bounds.top < 0 || bounds.bottom > window.innerHeight) {
                scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            }
        }
    }

    seek(event) {
        let element = event.target;

        while (element !== this.element && !this.unitByElement.has(element)) {
            element = element.parentElement;
        }

        const unit = this.unitByElement.get(element);
        if (!unit) {
            return;
        }

        this.audio.currentTime = unit.startMs / 1000;
        this.audio.play().catch(() => {});
    }

    noteManualScroll() {
        if (!this.audio.paused) {
            this.lastManualScrollAt = Date.now();
        }
    }

    noteScrollKey(event) {
        const acceptsKeyboardInput = event.target.matches?.('input, textarea, select, audio, video, [contenteditable]');
        if (!acceptsKeyboardInput && ['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '].includes(event.key)) {
            this.noteManualScroll();
        }
    }

    /**
     * Start watching whether the player is on screen, once it is playing. The observer watches the
     * placeholder when the audio is floating and the audio itself when it is not, since the element
     * being observed moves.
     */
    observeAudioVisibility() {
        if (this.visibilityObserver) {
            return;
        }

        this.visibilityObserver = new IntersectionObserver((entries) => {
            const entry = entries[entries.length - 1];
            if (!entry) {
                return;
            }
            if (entry.isIntersecting) {
                this.restoreAudio();
            } else if (!this.audio.paused) {
                this.floatAudio();
            }
            this.watchAnchor();
        }, { threshold: 0 });

        this.watchAnchor();
    }

    /** (Re)point the observer at whichever element currently holds the player's place in the page. */
    watchAnchor() {
        const anchor = this.audioPlaceholder ?? this.audio;
        if (!this.visibilityObserver || anchor === this.observedAnchor) {
            return;
        }

        if (this.observedAnchor) {
            this.visibilityObserver.unobserve(this.observedAnchor);
        }
        this.visibilityObserver.observe(anchor);
        this.observedAnchor = anchor;
    }

    floatAudio() {
        if (this.audioPlaceholder) {
            return;
        }

        this.audioPlaceholder = document.createElement('div');
        this.audioPlaceholder.style.height = `${this.audio.offsetHeight}px`;
        this.audio.before(this.audioPlaceholder);
        this.audioContainer = document.createElement('div');
        Object.assign(this.audioContainer.style, {
            position: 'fixed',
            left: '50%',
            bottom: '1rem',
            transform: 'translateX(-50%)',
            width: 'min(480px, calc(100vw - 2rem))',
            // Above Symfony's web debug toolbar (99999), which otherwise paints straight over the
            // pill in dev: document.elementFromPoint() at the pill's centre returned
            // DIV.sf-toolbar-icon, so the audio played with no visible controls. A sticky site
            // footer in production is the same hazard.
            zIndex: '100000',
            padding: '.5rem .75rem',
            border: '1px solid rgba(255, 255, 255, .35)',
            borderRadius: '999px',
            background: 'rgba(255, 255, 255, .72)',
            backdropFilter: 'blur(14px)',
            WebkitBackdropFilter: 'blur(14px)',
            boxShadow: '0 .5rem 1.5rem rgba(0, 0, 0, .2)',
        });
        document.body.append(this.audioContainer);
        this.audioContainer.append(this.audio);
        Object.assign(this.audio.style, {
            display: 'block',
            width: '100%',
            borderRadius: '999px',
        });
    }

    restoreAudio() {
        this.audioPlaceholder?.replaceWith(this.audio);
        this.audioPlaceholder = null;
        this.audioContainer?.remove();
        this.audioContainer = null;
        this.audio.style.cssText = this.originalAudioStyle;
    }
}
