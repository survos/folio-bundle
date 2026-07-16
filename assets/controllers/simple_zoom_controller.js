import { Controller } from '@hotwired/stimulus';

// Deliberately the simplest possible "zoom" -- one click fills the viewport with the photo
// (dimmed backdrop), click again or Escape to close. No pan, no pinch, no tiling -- see
// FolioItem.php's $useIiifViewer docblock for why OpenSeadragon isn't used here for openfoto.
export default class extends Controller {
    connect() {
        this._onKeydown = this._onKeydown.bind(this);
    }

    toggle() {
        const zoomed = this.element.classList.toggle('is-zoomed');
        if (zoomed) {
            // Capture phase, so this runs before the enclosing Bootstrap modal's own
            // Escape-to-close handler -- without this, the first Escape closed the
            // whole photo modal instead of just un-zooming.
            document.addEventListener('keydown', this._onKeydown, true);
        } else {
            document.removeEventListener('keydown', this._onKeydown, true);
        }
    }

    _onKeydown(event) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            this.element.classList.remove('is-zoomed');
            document.removeEventListener('keydown', this._onKeydown, true);
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKeydown, true);
    }
}
