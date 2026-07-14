import { Controller } from '@hotwired/stimulus';

/*
 * Minimal share button: Web Share API where available (mobile browsers, some desktop),
 * falling back to copying the URL to the clipboard with a brief label swap for feedback.
 */
export default class extends Controller {
    static values = {
        url: String,
        title: String,
    };

    async share() {
        if (navigator.share) {
            try {
                await navigator.share({ title: this.titleValue, url: this.urlValue });
                return;
            } catch {
                // User cancelled the share sheet, or the platform rejected it -- fall through
                // to clipboard-copy rather than leaving the click looking like it did nothing.
            }
        }

        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.flashLabel('Link copied');
        } catch {
            this.flashLabel('Could not copy link');
        }
    }

    flashLabel(text) {
        const original = this.element.textContent;
        this.element.textContent = text;
        this.element.disabled = true;

        setTimeout(() => {
            this.element.textContent = original;
            this.element.disabled = false;
        }, 1500);
    }
}
