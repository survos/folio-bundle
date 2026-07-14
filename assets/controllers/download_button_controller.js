import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

/*
 * Pretty download dialog (SweetAlert2): preview image, tombstone fields (credit/date/rights),
 * an editable filename, and an optional "copy citation to clipboard" step before triggering the
 * actual browser download. No format choice yet (single downloadUrl) -- once the IIIF viewer
 * comes back, this is where a display-JPG vs. original-quality choice would go.
 */
export default class extends Controller {
    static values = {
        title: String,
        thumbnail: String,
        creator: String,
        date: String,
        license: String,
        filename: String,
        citation: String,
        url: String,
    };

    async download() {
        const result = await Swal.fire({
            title: this.titleValue,
            width: 420,
            imageUrl: this.thumbnailValue || undefined,
            imageWidth: 220,
            html: `
                <div style="text-align:left">
                    <table class="table table-sm">
                        ${this.creatorValue ? `<tr><th>Credit</th><td>${this.escape(this.creatorValue)}</td></tr>` : ''}
                        ${this.dateValue ? `<tr><th>Date</th><td>${this.escape(this.dateValue)}</td></tr>` : ''}
                        ${this.licenseValue ? `<tr><th>Rights</th><td>${this.escape(this.licenseValue)}</td></tr>` : ''}
                    </table>

                    <div class="mb-3">
                        <label class="form-label">Filename</label>
                        <input id="folio-download-filename" class="swal2-input" value="${this.escape(this.filenameValue)}">
                    </div>

                    ${this.citationValue ? `
                        <label>
                            <input id="folio-download-citation" type="checkbox" checked>
                            Copy citation to clipboard
                        </label>
                    ` : ''}
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Download',
            focusConfirm: false,
        });

        if (!result.isConfirmed) {
            return;
        }

        const filenameInput = document.getElementById('folio-download-filename');
        const filename = filenameInput ? filenameInput.value : this.filenameValue;

        const citationCheckbox = document.getElementById('folio-download-citation');
        if (citationCheckbox?.checked && this.citationValue) {
            navigator.clipboard.writeText(this.citationValue);
        }

        await this.triggerDownload(this.urlValue, filename);
    }

    // A plain <a download> is silently ignored by browsers for cross-origin URLs (they just
    // navigate to the source page instead of downloading) -- fetch the image ourselves and
    // download the resulting blob, which works regardless of origin. Falls back to opening the
    // source URL directly if the fetch fails (e.g. the source host doesn't send permissive CORS
    // headers).
    async triggerDownload(url, filename) {
        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            a.click();
            URL.revokeObjectURL(blobUrl);
        } catch {
            window.open(url, '_blank', 'noopener');
        }
    }

    escape(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[char]);
    }
}
