const JSONLD_TYPE = 'application/ld+json';
const MERGE_PATCH_TYPE = 'application/merge-patch+json';

function collectionItems(payload) {
    if (Array.isArray(payload)) {
        return payload;
    }

    return payload?.member || payload?.['hydra:member'] || [];
}

export async function apiRequest(url, options = {}) {
    const headers = new Headers(options.headers || {});

    if (!headers.has('Accept')) {
        headers.set('Accept', JSONLD_TYPE);
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    if (response.status === 204) {
        return null;
    }

    const contentType = response.headers.get('Content-Type') || '';
    const payload = contentType.includes('json') ? await response.json() : await response.text();

    if (!response.ok) {
        const detail = typeof payload === 'object' ? (payload.detail || payload['hydra:description'] || payload.title) : payload;
        throw new Error(detail || `Request failed with status ${response.status}`);
    }

    return payload;
}

export async function listFolders() {
    return collectionItems(await apiRequest('/api/folders'));
}

export async function createFolderRequest(name, visibility = 'private') {
    return apiRequest('/api/folders', {
        method: 'POST',
        headers: { 'Content-Type': JSONLD_TYPE },
        body: JSON.stringify({ name, visibility }),
    });
}

export async function updateFolder(folderIri, payload) {
    return apiRequest(folderIri, {
        method: 'PATCH',
        headers: { 'Content-Type': MERGE_PATCH_TYPE },
        body: JSON.stringify(payload),
    });
}

export async function deleteFolderRequest(folderIri) {
    return apiRequest(folderIri, { method: 'DELETE' });
}

export async function listBookmarks(filters = {}) {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, value);
        }
    });

    const query = params.toString();

    return collectionItems(await apiRequest(`/api/bookmarks${query ? `?${query}` : ''}`));
}

export async function createBookmarkRequest(payload) {
    return apiRequest('/api/bookmarks', {
        method: 'POST',
        headers: { 'Content-Type': JSONLD_TYPE },
        body: JSON.stringify(payload),
    });
}

export async function moveBookmarkRequest(bookmarkIri, folderIri) {
    return apiRequest(bookmarkIri, {
        method: 'PATCH',
        headers: { 'Content-Type': MERGE_PATCH_TYPE },
        body: JSON.stringify({ folder: folderIri }),
    });
}

export async function deleteBookmarkRequest(bookmarkIri) {
    return apiRequest(bookmarkIri, { method: 'DELETE' });
}

export function resourceIri(resource) {
    if (typeof resource === 'string') {
        return resource;
    }

    return resource?.['@id'] || null;
}

export function bookmarkFolderIri(bookmark) {
    return resourceIri(bookmark?.folder);
}

export function sortFolders(folders) {
    return [...folders].sort((a, b) => {
        const aName = (a?.name || '').toLocaleLowerCase();
        const bName = (b?.name || '').toLocaleLowerCase();

        return aName.localeCompare(bName, undefined, {
            numeric: true,
            sensitivity: 'base',
        });
    });
}
