import { Controller } from '@hotwired/stimulus';
import {
    listFolders,
    createFolderRequest,
    updateFolder,
    deleteFolderRequest,
    listBookmarks,
    createBookmarkRequest,
    moveBookmarkRequest,
    deleteBookmarkRequest,
    resourceIri,
    bookmarkFolderIri,
    sortFolders,
} from '../bookmark_api.js';

export default class extends Controller {
    static targets = [
        'button',
        'buttonLabel',
        'menu',
        'folderList',
        'newFolderInput',
        'removeButton',
        'folderForm',
        'folderName',
        'folderVisibility',
        'groups',
        'empty',
        'loading',
        'status',
        'searchInput',
        'searchEmpty',
    ];

    static values = {
        provider: String,
        dataset: String,
        coreCode: String,
        localId: String,
        dtoType: String,
        label: String,
        initialFolders: Array,
        initialBookmarks: Array,
        selectedFolder: String,
        rowUrlTemplate: String,
    };

    connect() {
        this.open = false;
        this.busy = false;
        this.error = null;
        this.folders = [];
        this.bookmarks = [];
        this.bookmark = null;
        this.searchQuery = '';
        this.isLibrary = this.hasGroupsTarget || this.hasFolderFormTarget;

        if (this.isLibrary && (this.hasInitialFoldersValue || this.hasInitialBookmarksValue)) {
            this.folders = this.hasInitialFoldersValue ? sortFolders(this.initialFoldersValue) : [];
            this.bookmarks = this.hasInitialBookmarksValue ? this.initialBookmarksValue : [];
        } else {
            this.load();
            this.render();
        }

        this.onBookmarksChanged = (event) => {
            if (event.detail?.source !== this.element) {
                this.load();
            }
        };
        window.addEventListener('bookmarks:changed', this.onBookmarksChanged);

        this.onCloseAll = (event) => {
            if (event.detail?.source === this.element) {
                return;
            }
            this.close();
        };
        window.addEventListener('bookmark:close-all', this.onCloseAll);
    }

    disconnect() {
        window.removeEventListener('bookmarks:changed', this.onBookmarksChanged);
        window.removeEventListener('bookmark:close-all', this.onCloseAll);
    }

    async load() {
        if (this.isLibrary) {
            await this.loadLibrary();
            return;
        }

        await this.loadWidget();
    }

    async loadWidget() {
        this.busy = true;
        this.error = null;
        this.render();

        try {
            const [folders, bookmarks] = await Promise.all([
                this.loadFolders(),
                listBookmarks({
                    provider: this.providerValue,
                    dataset: this.datasetValue,
                    coreCode: this.coreCodeValue,
                }),
            ]);

            this.folders = sortFolders(folders);
            this.bookmark = bookmarks.find((bookmark) => bookmark.localId === this.localIdValue) || null;
        } catch (error) {
            this.error = error.message || 'Bookmark data could not be loaded.';
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async loadLibrary() {
        this.busy = true;
        this.error = null;
        this.render();

        try {
            const [folders, bookmarks] = await Promise.all([listFolders(), listBookmarks()]);
            this.folders = sortFolders(folders);
            this.bookmarks = bookmarks;
        } catch (error) {
            this.error = error.message || 'Bookmarks could not be loaded.';
        } finally {
            this.busy = false;
            this.render();
        }
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();

        const shouldOpen = !this.open;

        window.dispatchEvent(new CustomEvent('bookmark:close-all', {
            detail: { source: this.element },
        }));

        this.open = shouldOpen;
        this.render();

        if (this.open && this.hasNewFolderInputTarget) {
            this.newFolderInputTarget.value = '';
            this.newFolderInputTarget.focus({ preventScroll: true });
        }
    }

    close() {
        if (!this.open) {
            return;
        }

        this.open = false;
        this.render();
    }

    closeOnOutsideClick(event) {
        if (!this.open || this.element.contains(event.target)) {
            return;
        }

        this.close();
    }

    async selectFolder(event) {
        event.preventDefault();

        await this.saveBookmark(event.params.folder || null);
    }

    async loadFolders() {
        return listFolders();
    }

    async createFolder(event) {
        event.preventDefault();

        if (this.isLibrary) {
            await this.createLibraryFolder();
            return;
        }

        await this.createWidgetFolder();
    }

    async createWidgetFolder() {
        const folderName = this.newFolderInputTarget.value.trim();

        if (!folderName) {
            return;
        }

        this.busy = true;
        this.error = null;
        this.render();

        try {
            const folder = await createFolderRequest(folderName);
            this.folders = [...this.folders.filter((item) => resourceIri(item) !== resourceIri(folder)), folder];
            await this.saveBookmark(resourceIri(folder), false);
            this.newFolderInputTarget.value = '';
        } catch (error) {
            this.error = error.message || 'Folder could not be created.';
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async createLibraryFolder() {
        const name = this.folderNameTarget.value.trim();

        if (!name) {
            return;
        }

        await this.run(async () => {
            const visibility = this.hasFolderVisibilityTarget ? this.folderVisibilityTarget.value : 'private';

            await createFolderRequest(name, visibility);
            this.folderNameTarget.value = '';

            if (this.hasFolderVisibilityTarget) {
                this.folderVisibilityTarget.value = 'private';
            }

            await this.load();
        }, 'Folder could not be created.');
    }

    async renameFolder(event) {
        event.preventDefault();

        const folder = this.folderByIri(event.params.folder);
        const nextName = window.prompt('Rename folder', folder?.name || '');

        if (!folder || !nextName || nextName.trim() === folder.name) {
            return;
        }

        await this.run(async () => {
            await updateFolder(resourceIri(folder), { name: nextName.trim() });
            await this.load();
        }, 'Folder could not be renamed.');
    }

    async updateFolderVisibility(event) {
        const folderIri = event.params.folder;
        const visibility = event.target.value;

        await this.run(async () => {
            await updateFolder(folderIri, { visibility });
            await this.load();
        }, 'Folder visibility could not be updated.');
    }

    async deleteFolder(event) {
        event.preventDefault();

        const folder = this.folderByIri(event.params.folder);

        if (!folder || !window.confirm(`Delete "${folder.name}"? Bookmarks in this folder will become Unfiled.`)) {
            return;
        }

        await this.run(async () => {
            await deleteFolderRequest(resourceIri(folder));
            await this.load();
        }, 'Folder could not be deleted.');
    }

    async moveBookmark(event) {
        const bookmarkIri = event.params.bookmark;
        const folderIri = event.target.value || null;

        await this.run(async () => {
            await moveBookmarkRequest(bookmarkIri, folderIri);
            await this.load();
            this.broadcastChange();
        }, 'Bookmark could not be moved.');
    }

    async saveBookmark(folderIri = null, closeAfterSave = true) {
        this.busy = true;
        this.error = null;
        this.render();

        try {
            this.bookmark = await createBookmarkRequest({
                provider: this.providerValue,
                dataset: this.datasetValue,
                coreCode: this.coreCodeValue,
                localId: this.localIdValue,
                folder: folderIri,
                dtoType: this.dtoTypeValue || null,
                label: this.labelValue || null,
            });

            if (closeAfterSave) {
                this.open = false;
            }

            this.broadcastChange();
        } catch (error) {
            this.error = error.message || 'Bookmark could not be saved.';
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async removeBookmark(event) {
        event?.preventDefault();

        const bookmarkIri = resourceIri(this.bookmark);

        if (!bookmarkIri) {
            return;
        }

        this.busy = true;
        this.error = null;
        this.render();

        try {
            await deleteBookmarkRequest(bookmarkIri);
            this.bookmark = null;
            this.open = false;
            this.broadcastChange();
        } catch (error) {
            this.error = error.message || 'Bookmark could not be removed.';
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async deleteBookmark(event) {
        event.preventDefault();

        await this.run(async () => {
            await deleteBookmarkRequest(event.params.bookmark);
            await this.load();
            this.broadcastChange();
        }, 'Bookmark could not be removed.');
    }

    async run(callback, fallbackMessage) {
        this.busy = true;
        this.error = null;
        this.render();

        try {
            await callback();
        } catch (error) {
            this.error = error.message || fallbackMessage;
        } finally {
            this.busy = false;
            this.render();
        }
    }

    broadcastChange() {
        window.dispatchEvent(new CustomEvent('bookmarks:changed', { detail: { source: this.element } }));
    }

    render() {
        if (this.isLibrary) {
            this.renderLibrary();
            return;
        }

        this.renderWidget();
    }

    renderWidget() {
        this.renderButton();
        this.renderMenu();
        this.renderWidgetFolders();
        this.renderStatus();
    }

    renderButton() {
        if (!this.hasButtonTarget) {
            return;
        }

        this.buttonTarget.classList.toggle('btn-primary', Boolean(this.bookmark));
        this.buttonTarget.classList.toggle('bg-white', !this.bookmark);
        this.buttonTarget.classList.toggle('bg-opacity-75', !this.bookmark);
        this.buttonTarget.classList.toggle('btn-loading', this.busy);
        this.buttonTarget.disabled = this.busy;
        this.buttonTarget.setAttribute('aria-expanded', String(this.open));

        if (this.hasButtonLabelTarget) {
            const folderName = this.folderName(bookmarkFolderIri(this.bookmark));
            this.buttonLabelTarget.textContent = this.bookmark ? `Bookmarked: ${folderName}` : 'Bookmark';
        }
    }

    renderMenu() {
        if (!this.hasMenuTarget) {
            return;
        }

        this.menuTarget.classList.toggle('show', this.open);
        this.menuTarget.style.display = this.open ? 'block' : '';

        if (this.hasRemoveButtonTarget) {
            this.removeButtonTarget.classList.toggle('d-none', !this.bookmark);
            this.removeButtonTarget.disabled = this.busy;
        }
    }

    renderWidgetFolders() {
        if (!this.hasFolderListTarget) {
            return;
        }

        this.folderListTarget.replaceChildren();
        this.folderListTarget.appendChild(this.folderButton(null, 'Unfiled'));

        this.folders.forEach((folder) => {
            this.folderListTarget.appendChild(this.folderButton(resourceIri(folder), folder.name));
        });
    }

    folderButton(folderIri, name) {
        const selected = Boolean(this.bookmark) && bookmarkFolderIri(this.bookmark) === folderIri;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `dropdown-item d-flex align-items-center gap-2 ${selected ? 'active' : ''}`;
        button.disabled = this.busy;
        button.dataset.action = 'bookmark#selectFolder';
        button.dataset.bookmarkFolderParam = folderIri || '';
        button.setAttribute('role', 'menuitemradio');
        button.setAttribute('aria-checked', String(selected));

        const label = document.createElement('span');
        label.className = 'text-truncate min-w-0';
        label.textContent = name;

        const marker = document.createElement('span');
        marker.className = `ms-auto ${selected ? '' : 'invisible'}`;
        marker.setAttribute('aria-hidden', 'true');
        marker.textContent = '✓';

        button.append(label, marker);

        return button;
    }

    renderLibrary() {
        this.renderChrome();
        this.renderLibraryFolders();
        this.renderGroups();
    }

    renderChrome() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.toggle('d-none', !this.busy);
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle(
                'd-none',
                this.busy || this.bookmarks.length > 0 || Boolean(this.error)
            );
        }

        if (this.hasSearchEmptyTarget) {
            this.searchEmptyTarget.classList.add('d-none');
        }

        this.renderStatus();
    }

    renderStatus() {
        if (!this.hasStatusTarget) {
            return;
        }

        this.statusTarget.textContent = this.error || '';
        this.statusTarget.classList.toggle('d-none', !this.error);
    }

    renderLibraryFolders() {
        if (!this.hasFolderListTarget) {
            return;
        }

        this.folderListTarget.replaceChildren();

        this.folderListTarget.appendChild(this.folderSummary({
            name: 'All Folders',
            icon: 'folders',
            href: this.bookmarksUrl(),
            count: this.bookmarks.length,
            fixed: true,
            active: this.selectedFolderValue === '',
        }));

        this.folderListTarget.appendChild(this.folderSummary({
            name: 'Unfiled',
            icon: 'folderOff',
            iri: '',
            href: this.bookmarksUrl('unfiled'),
            count: this.bookmarks.filter((bookmark) => !bookmarkFolderIri(bookmark)).length,
            fixed: true,
            active: this.selectedFolderValue === 'unfiled',
        }));

        this.folders.forEach((folder) => {
            const folderIri = resourceIri(folder);
            this.folderListTarget.appendChild(this.folderSummary({
                name: folder.name,
                icon: 'folder',
                iri: folderIri,
                href: this.bookmarksUrl(folder.slug),
                visibility: folder.visibility || 'private',
                count: this.bookmarks.filter((bookmark) => bookmarkFolderIri(bookmark) === folderIri).length,
                fixed: false,
                active: this.selectedFolderValue === folderIri,
            }));
        });
    }

    folderSummary({ name, icon, iri, href, visibility, count, fixed, active = false }) {
        const item = document.createElement(href && fixed ? 'a' : 'div');
        item.className = `${href && fixed ? 'list-group-item list-group-item-action' : 'list-group-item'} ${active ? 'active' : ''}`.trim();

        if (href && fixed) {
            item.href = href;
        }

        const row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-2';

        const main = document.createElement(href && !fixed ? 'a' : 'div');
        main.className = 'text-reset text-decoration-none min-w-0 flex-fill';

        if (href && !fixed) {
            main.href = href;
        }

        const inner = document.createElement('div');
        inner.className = 'd-flex align-items-center gap-2';

        inner.appendChild(this.icon(icon || 'folder'));

        const copy = document.createElement('div');
        copy.className = fixed ? 'min-w-0 flex-fill d-flex justify-content-between' : 'min-w-0';

        const title = document.createElement('div');
        title.className = 'fw-semibold text-truncate';
        title.textContent = name;

        copy.appendChild(title);

        if (fixed) {
            const fixedBadge = document.createElement('span');
            fixedBadge.className = 'badge bg-secondary-lt';
            fixedBadge.textContent = String(count);
            copy.appendChild(fixedBadge);
        }

        inner.appendChild(copy);
        main.appendChild(inner);
        row.appendChild(main);

        if (!fixed) {
            const badge = document.createElement('span');
            badge.className = `badge ${active ? 'bg-white text-primary' : 'bg-secondary-lt'}`;
            badge.textContent = String(count);
            row.appendChild(badge);

            row.appendChild(this.folderActionsDropdown({ iri, visibility, active }));
        }

        item.appendChild(row);

        return item;
    }

    folderActionsDropdown({ iri, visibility, active }) {
        const dropdown = document.createElement('div');
        dropdown.className = 'dropdown flex-shrink-0';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-action btn-sm bookmark-library__icon-action';
        button.dataset.bsToggle = 'dropdown';
        button.setAttribute('aria-label', 'Folder actions');
        button.innerHTML = this.iconHtml('dots');

        const menu = document.createElement('div');
        menu.className = 'dropdown-menu dropdown-menu-end';

        const visibilityWrap = document.createElement('div');
        visibilityWrap.className = 'px-3 py-2';

        const label = document.createElement('label');
        label.className = 'form-label small mb-1';
        label.textContent = 'Visibility';

        const visibilitySelect = document.createElement('select');
        visibilitySelect.className = 'form-select form-select-sm';
        visibilitySelect.dataset.action = 'bookmark#updateFolderVisibility';
        visibilitySelect.dataset.bookmarkFolderParam = iri;

        [
            ['private', 'Private'],
            ['unlisted', 'Unlisted'],
            ['public', 'Public'],
        ].forEach(([value, text]) => {
            visibilitySelect.appendChild(new Option(text, value, visibility === value, visibility === value));
        });

        visibilityWrap.append(label, visibilitySelect);

        const divider = document.createElement('div');
        divider.className = 'dropdown-divider';

        const renameButton = document.createElement('button');
        renameButton.type = 'button';
        renameButton.className = 'dropdown-item';
        renameButton.dataset.action = 'bookmark#renameFolder';
        renameButton.dataset.bookmarkFolderParam = iri;
        renameButton.innerHTML = `${this.iconHtml('pencil')} Rename`;

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'dropdown-item text-danger';
        deleteButton.dataset.action = 'bookmark#deleteFolder';
        deleteButton.dataset.bookmarkFolderParam = iri;
        deleteButton.innerHTML = `${this.iconHtml('trash')} Delete`;

        menu.append(visibilityWrap, divider, renameButton, deleteButton);
        dropdown.append(button, menu);

        return dropdown;
    }

    iconHtml(name) {
        const icons = {
            folders: '<svg xmlns="http://www.w3.org/2000/svg" class="icon flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 13h6"/><path d="M12 10v6"/><path d="M3 7h5l2 2h11v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/><path d="M3 7v-2a2 2 0 0 1 2 -2h3l2 2h5a2 2 0 0 1 2 2v2"/></svg>',
            folder: '<svg xmlns="http://www.w3.org/2000/svg" class="icon flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2"/></svg>',
            folderOff: '<svg xmlns="http://www.w3.org/2000/svg" class="icon flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M19 19h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 .179 -.823m2.821 -1.177h3l3 3h7a2 2 0 0 1 2 2v8"/></svg>',
            dots: '<svg xmlns="http://www.w3.org/2000/svg" class="icon flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg>',
            pencil: '<svg xmlns="http://www.w3.org/2000/svg" class="icon flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4"/><path d="M13.5 6.5l4 4"/></svg>',
            trash: '<svg xmlns="http://www.w3.org/2000/svg" class="icon flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3h6v3"/></svg>',
        };

        return icons[name] || icons.folder;
    }

    icon(name) {
        const template = document.createElement('template');
        template.innerHTML = this.iconHtml(name);

        return template.content.firstElementChild.cloneNode(true);
    }

    renderGroups() {
        if (!this.hasGroupsTarget) {
            return;
        }

        this.groupsTarget.replaceChildren();

        if (this.hasSearchEmptyTarget) {
            this.searchEmptyTarget.classList.add('d-none');
        }

        const visibleBookmarks = this.filteredBookmarks();

        if (visibleBookmarks.length === 0) {
            if (this.searchQuery && this.hasSearchEmptyTarget) {
                this.searchEmptyTarget.classList.remove('d-none');
            }

            return;
        }

        let folderBuckets = [
            { name: 'Unfiled', iri: null },
            ...this.folders.map((folder) => ({ name: folder.name, iri: resourceIri(folder) })),
        ];

        if (this.selectedFolderValue === 'unfiled') {
            folderBuckets = [{ name: 'Unfiled', iri: null }];
        } else if (this.selectedFolderValue !== '') {
            folderBuckets = folderBuckets.filter((folder) => folder.iri === this.selectedFolderValue);
        }

        folderBuckets.forEach((folder) => {
            const bookmarks = visibleBookmarks.filter((bookmark) => bookmarkFolderIri(bookmark) === folder.iri);

            if (bookmarks.length === 0) {
                return;
            }

            this.groupsTarget.appendChild(this.groupElement(folder.name, bookmarks));
        });
    }

    groupElement(name, bookmarks) {
        const section = document.createElement('section');
        section.className = 'card bookmark-library__group';

        const header = document.createElement('div');
        header.className = 'card-header';

        const headerRow = document.createElement('div');
        headerRow.className = 'd-flex align-items-center justify-content-between w-100 gap-3';

        const titleWrap = document.createElement('div');
        titleWrap.className = 'd-flex align-items-center gap-2 min-w-0';

        const folderIcon = this.icon(name === 'Unfiled' ? 'folderOff' : 'folder');
        folderIcon.classList.add('text-muted');

        const title = document.createElement('h2');
        title.className = 'card-title mb-0 text-truncate';
        title.textContent = name;

        titleWrap.append(folderIcon, title);

        const count = document.createElement('span');
        count.className = 'badge bg-secondary-lt';
        count.textContent = String(bookmarks.length);

        headerRow.append(titleWrap, count);
        header.appendChild(headerRow);

        const list = document.createElement('div');
        list.className = 'list-group list-group-flush bookmark-library__bookmark-list';

        bookmarks.forEach((bookmark) => list.appendChild(this.bookmarkRow(bookmark)));

        section.append(header, list);

        return section;
    }

    bookmarkRow(bookmark) {
        const row = document.createElement('div');
        row.className = 'list-group-item';

        const layout = document.createElement('div');
        layout.className = 'row align-items-center g-3';

        const main = document.createElement('div');
        main.className = 'col min-w-0';

        const title = document.createElement('a');
        title.className = 'fw-semibold text-decoration-none text-truncate d-block';
        title.href = this.rowUrl(bookmark.rowRouteParams);
        title.textContent = bookmark.label || bookmark.localId;

        const meta = document.createElement('div');
        meta.className = 'text-muted small text-truncate mt-1';
        meta.textContent = `${bookmark.folioCode} / ${bookmark.coreCode}${bookmark.dtoType ? ` / ${bookmark.dtoType}` : ''} / ${bookmark.localId}`;

        main.append(title, meta);

        const selectCol = document.createElement('div');
        selectCol.className = 'col-md-5 col-xl-4';

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.setAttribute('aria-label', 'Move bookmark');
        select.dataset.action = 'bookmark#moveBookmark';
        select.dataset.bookmarkBookmarkParam = resourceIri(bookmark);

        select.appendChild(new Option('Unfiled', '', !bookmarkFolderIri(bookmark), !bookmarkFolderIri(bookmark)));

        this.folders.forEach((folder) => {
            const folderIri = resourceIri(folder);
            const selected = bookmarkFolderIri(bookmark) === folderIri;

            select.appendChild(new Option(folder.name, folderIri, selected, selected));
        });

        selectCol.appendChild(select);

        const removeCol = document.createElement('div');
        removeCol.className = 'col-auto';

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn btn-icon btn-sm bookmark-library__icon-action bookmark-library__remove-action';
        removeButton.setAttribute('aria-label', 'Remove bookmark');
        removeButton.dataset.action = 'bookmark#deleteBookmark';
        removeButton.dataset.bookmarkBookmarkParam = resourceIri(bookmark);
        removeButton.appendChild(this.icon('trash'));

        removeCol.appendChild(removeButton);

        layout.append(main, selectCol, removeCol);
        row.appendChild(layout);

        return row;
    }

    iconButton(title, action, param, label, extraClass = '') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-sm btn-outline-secondary ${extraClass}`.trim();
        button.title = title;
        button.textContent = label;
        button.dataset.action = action;

        if (action.includes('Folder')) {
            button.dataset.bookmarkFolderParam = param;
        } else {
            button.dataset.bookmarkBookmarkParam = param;
        }

        return button;
    }

    folderByIri(iri) {
        return this.folders.find((folder) => resourceIri(folder) === iri);
    }

    bookmarksUrl(folder = '') {
        const url = new URL(window.location.href);
        url.pathname = '/bookmarks';

        if (folder) {
            url.searchParams.set('folder', folder);
        } else {
            url.searchParams.delete('folder');
        }

        return `${url.pathname}${url.search}`;
    }

    folderName(folderIri) {
        if (!folderIri) {
            return 'Unfiled';
        }

        return this.folders.find((folder) => resourceIri(folder) === folderIri)?.name || 'Folder';
    }

    rowUrl(params) {
        if (!params || !this.hasRowUrlTemplateValue) {
            return '#';
        }

        const enc = (value) => encodeURIComponent(value ?? '');

        return this.rowUrlTemplateValue
            .replace('__PROVIDER__', enc(params.provider))
            .replace('__DATASET__', enc(params.dataset))
            .replace('__CORE_CODE__', enc(params.coreCode))
            .replace('__DTO_TYPE__', enc(params.dtoType || 'row'))
            .replace('__LOCAL_ID__', enc(params.localId));
    }

    searchBookmarks(event) {
        this.searchQuery = event.target.value.trim().toLocaleLowerCase();
        this.renderGroups();
    }

    filteredBookmarks() {
        if (!this.searchQuery) {
            return this.bookmarks;
        }

        return this.bookmarks.filter((bookmark) => {
            const haystack = [
                bookmark.label,
                bookmark.localId,
                bookmark.folioCode,
                bookmark.coreCode,
                bookmark.dtoType,
                bookmark.provider,
                bookmark.dataset,
            ]
                .filter(Boolean)
                .join(' ')
                .toLocaleLowerCase();

            return haystack.includes(this.searchQuery);
        });
    }
}
