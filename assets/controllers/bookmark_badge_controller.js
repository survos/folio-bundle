import { Controller } from '@hotwired/stimulus';
import { listFolders, listBookmarks, resourceIri, bookmarkFolderIri } from '../bookmark_api.js';

/**
 * Read-only "which folder is this bookmarked in" indicator for grid/search results.
 * Adding, removing, and moving bookmarks is only available on the detail page (see
 * the `bookmark` controller / bookmark/_widget.html.twig).
 */
export default class extends Controller {
    static targets = ['label'];

    static values = {
        provider: String,
        dataset: String,
        coreCode: String,
        localId: String,
    };

    async connect() {
        try {
            const [folders, bookmarks] = await Promise.all([
                listFolders(),
                listBookmarks({
                    provider: this.providerValue,
                    dataset: this.datasetValue,
                    coreCode: this.coreCodeValue,
                }),
            ]);

            const bookmark = bookmarks.find((candidate) => candidate.localId === this.localIdValue);

            if (!bookmark) {
                return;
            }

            const folderIri = bookmarkFolderIri(bookmark);
            const folder = folders.find((candidate) => resourceIri(candidate) === folderIri);

            this.labelTarget.textContent = folder ? folder.name : 'Unfiled';
            this.element.classList.remove('d-none');
        } catch (error) {
            // Silently leave the badge hidden — a failed lookup shouldn't break the grid.
        }
    }
}
