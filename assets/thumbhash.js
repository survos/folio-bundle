/*
 * ThumbHash placeholders for folio images: a ~25-byte hash (Page::$thumbHash, base64) decoded
 * into a blurred ~32 px preview painted behind an <img> until the real thumbnail arrives.
 *
 * thumbHashToRGBA / thumbHashToApproximateAspectRatio are the reference decoder by Evan Wallace
 * (https://github.com/evanw/thumbhash, MIT), unchanged.
 */

export function thumbHashToRGBA(hash) {
    const { PI, min, max, cos, round } = Math;

    const header24 = hash[0] | (hash[1] << 8) | (hash[2] << 16);
    const header16 = hash[3] | (hash[4] << 8);
    const l_dc = (header24 & 63) / 63;
    const p_dc = ((header24 >> 6) & 63) / 31.5 - 1;
    const q_dc = ((header24 >> 12) & 63) / 31.5 - 1;
    const l_scale = ((header24 >> 18) & 31) / 31;
    const hasAlpha = header24 >> 23;
    const p_scale = ((header16 >> 3) & 63) / 63;
    const q_scale = ((header16 >> 9) & 63) / 63;
    const isLandscape = header16 >> 15;
    const lx = max(3, isLandscape ? hasAlpha ? 5 : 7 : header16 & 7);
    const ly = max(3, isLandscape ? header16 & 7 : hasAlpha ? 5 : 7);
    const a_dc = hasAlpha ? (hash[5] & 15) / 15 : 1;
    const a_scale = (hash[5] >> 4) / 15;

    const ac_start = hasAlpha ? 6 : 5;
    let ac_index = 0;
    const decodeChannel = (nx, ny, scale) => {
        const ac = [];
        for (let cy = 0; cy < ny; cy++)
            for (let cx = cy ? 0 : 1; cx * ny < nx * (ny - cy); cx++)
                ac.push((((hash[ac_start + (ac_index >> 1)] >> ((ac_index++ & 1) << 2)) & 15) / 7.5 - 1) * scale);
        return ac;
    };
    const l_ac = decodeChannel(lx, ly, l_scale);
    const p_ac = decodeChannel(3, 3, p_scale * 1.25);
    const q_ac = decodeChannel(3, 3, q_scale * 1.25);
    const a_ac = hasAlpha && decodeChannel(5, 5, a_scale);

    const ratio = thumbHashToApproximateAspectRatio(hash);
    const w = round(ratio > 1 ? 32 : 32 * ratio);
    const h = round(ratio > 1 ? 32 / ratio : 32);
    const rgba = new Uint8Array(w * h * 4), fx = [], fy = [];
    for (let y = 0, i = 0; y < h; y++) {
        for (let x = 0; x < w; x++, i += 4) {
            let l = l_dc, p = p_dc, q = q_dc, a = a_dc;
            for (let cx = 0, n = max(lx, hasAlpha ? 5 : 3); cx < n; cx++) fx[cx] = cos(PI / w * (x + 0.5) * cx);
            for (let cy = 0, n = max(ly, hasAlpha ? 5 : 3); cy < n; cy++) fy[cy] = cos(PI / h * (y + 0.5) * cy);
            for (let cy = 0, j = 0; cy < ly; cy++)
                for (let cx = cy ? 0 : 1, fy2 = fy[cy] * 2; cx * ly < lx * (ly - cy); cx++, j++)
                    l += l_ac[j] * fx[cx] * fy2;
            for (let cy = 0, j = 0; cy < 3; cy++) {
                for (let cx = cy ? 0 : 1, fy2 = fy[cy] * 2; cx < 3 - cy; cx++, j++) {
                    const f = fx[cx] * fy2;
                    p += p_ac[j] * f;
                    q += q_ac[j] * f;
                }
            }
            if (hasAlpha)
                for (let cy = 0, j = 0; cy < 5; cy++)
                    for (let cx = cy ? 0 : 1, fy2 = fy[cy] * 2; cx < 5 - cy; cx++, j++)
                        a += a_ac[j] * fx[cx] * fy2;
            const b = l - 2 / 3 * p;
            const r = (3 * l - b + q) / 2;
            const g = r - q;
            rgba[i] = max(0, 255 * min(1, r));
            rgba[i + 1] = max(0, 255 * min(1, g));
            rgba[i + 2] = max(0, 255 * min(1, b));
            rgba[i + 3] = max(0, 255 * min(1, a));
        }
    }
    return { w, h, rgba };
}

export function thumbHashToApproximateAspectRatio(hash) {
    const header = hash[3];
    const hasAlpha = hash[2] & 0x80;
    const isLandscape = hash[4] & 0x80;
    const lx = isLandscape ? hasAlpha ? 5 : 7 : header & 7;
    const ly = isLandscape ? header & 7 : hasAlpha ? 5 : 7;
    return lx / ly;
}

function fromBase64(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
}

let canvas;

/** A PNG data URL of the decoded preview, plus the source's approximate width/height. */
export function thumbHashToDataURL(base64) {
    const hash = fromBase64(base64);
    const { w, h, rgba } = thumbHashToRGBA(hash);
    canvas ??= document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const context = canvas.getContext('2d');
    const image = context.createImageData(w, h);
    image.data.set(rgba);
    context.putImageData(image, 0, 0);
    return { url: canvas.toDataURL(), aspect: thumbHashToApproximateAspectRatio(hash) };
}

/**
 * Paint an <img>'s placeholder from its data-thumbhash (else data-color): the blurred preview
 * as its background and, when the <img> has no width/height attributes, the hash's approximate
 * aspect ratio, so a lazy image holds its place in the layout before it has loaded. Both are
 * dropped once the image is in.
 */
export function paintPlaceholder(img) {
    const { thumbhash, color } = img.dataset;
    if (!thumbhash && !color) return;
    if (img.complete && img.naturalWidth > 0) return;
    try {
        if (thumbhash) {
            const { url, aspect } = thumbHashToDataURL(thumbhash);
            img.style.backgroundImage = `url(${url})`;
            img.style.backgroundSize = '100% 100%';
            // Only when the page's pixel size is unknown: width/height attributes are exact.
            if (!img.getAttribute('width')) img.style.aspectRatio = String(aspect);
        }
    } catch {
        // A malformed hash: fall back to the colour.
    }
    if (color) img.style.backgroundColor = color;
    img.addEventListener('load', () => {
        img.style.backgroundImage = '';
        img.style.aspectRatio = '';
    }, { once: true });
}
