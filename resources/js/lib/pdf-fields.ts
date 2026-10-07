import fontkit from '@pdf-lib/fontkit';
import { degrees, PDFDocument, StandardFonts, type PDFFont, type PDFImage } from 'pdf-lib';

/** Key of a box holding its own fixed text instead of a placeholder. */
export const TEXT_FIELD = 'text';

/** Key of a box holding an uploaded image (`src`: a PNG or JPEG data URL). */
export const IMAGE_FIELD = 'image';

/**
 * A field positioned on a PDF in the PDF editor: a placeholder key, IMAGE_FIELD with `src`, or TEXT_FIELD with
 * `text`. x, y, w and h are fractions of the page as displayed (rotation applied,
 * top-left origin), so they don't depend on the zoom level. `size` is the font size in points.
 */
export interface PdfField {
    key: string;
    text?: string;
    src?: string;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    size: number;
    align: 'left' | 'center' | 'right';
}

const MIN_FONT = 5;

/** Font family name used to show stamped text in the editor, matching the PDF output. */
export const STAMP_FONT_FAMILY = 'CrmPdfStamp';

let stampFont: Promise<ArrayBuffer | null> | null = null;

/**
 * DejaVu Sans, used for everything written onto a PDF: unlike the PDF built-in
 * fonts (Latin-1 only) it has the peso sign (₱) and most accented letters.
 * Loaded once, on demand; null if it can't be loaded (Helvetica is used instead).
 */
export function loadStampFont(): Promise<ArrayBuffer | null> {
    stampFont ??= import('dejavu-fonts-ttf/ttf/DejaVuSans.ttf?url')
        .then((m) => fetch(m.default))
        .then((r) => (r.ok ? r.arrayBuffer() : null))
        .catch(() => null);
    return stampFont;
}

/** Register the stamp font for on-screen text, so the editor shows what the PDF will look like. */
export async function registerStampFontFace(): Promise<void> {
    if (typeof FontFace === 'undefined' || [...document.fonts].some((f) => f.family === STAMP_FONT_FAMILY)) return;
    const bytes = await loadStampFont();
    if (!bytes) return;
    const face = new FontFace(STAMP_FONT_FAMILY, bytes);
    await face.load();
    document.fonts.add(face);
}

/** pdf.js, loaded on demand with its worker. */
export async function loadPdfjs() {
    const [pdfjs, worker] = await Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')]);
    pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
    return pdfjs;
}

/**
 * Render pages of a PDF to images (1-based page numbers). `scale` 2 = 144 dpi.
 * pdf.js paints a white page background, so JPEGs have no black corners.
 */
export async function pdfPagesToImages(pdfBytes: Uint8Array, pages: number[], type: 'image/png' | 'image/jpeg', scale = 2): Promise<Blob[]> {
    const pdfjs = await loadPdfjs();
    const doc = await pdfjs.getDocument({ data: pdfBytes.slice() }).promise;
    try {
        const blobs: Blob[] = [];
        for (const n of pages) {
            const page = await doc.getPage(n);
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            await page.render({ canvas, viewport }).promise;
            blobs.push(await new Promise<Blob>((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('The image could not be created.'))), type, 0.92)));
            page.cleanup();
        }
        return blobs;
    } finally {
        await doc.loadingTask.destroy();
    }
}

/** Save a blob as a file. */
export function saveBlob(blob: Blob, name: string) {
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(href), 60_000);
}

/** Characters the font lacks (e.g. Chinese) become "?" instead of failing. */
function encodable(font: PDFFont, text: string): string {
    const supported = new Set(font.getCharacterSet());
    return Array.from(text.normalize('NFC').replace(/[\r\n\t]+/g, ' '), (ch) => (supported.has(ch.codePointAt(0)!) ? ch : '?')).join('');
}

/**
 * Write each field's value (or its own text) into its box, shrinking the text to fit the box
 * width. Empty values are left blank. Returns the filled PDF.
 */
export async function stampPdf(source: ArrayBuffer, fields: PdfField[], values: Record<string, string>, fontBytes?: ArrayBuffer | null): Promise<Uint8Array> {
    const pdf = await PDFDocument.load(source, { ignoreEncryption: true });
    // Only the characters used are embedded (a few KB).
    const unicode = fontBytes === undefined ? await loadStampFont() : fontBytes;
    let font: PDFFont;
    if (unicode) {
        pdf.registerFontkit(fontkit);
        font = await pdf.embedFont(unicode, { subset: true });
    } else {
        font = await pdf.embedFont(StandardFonts.Helvetica);
    }
    const pages = pdf.getPages();
    // The same image placed several times is embedded once.
    const images = new Map<string, Promise<PDFImage>>();
    const embed = (src: string) => {
        if (!images.has(src)) images.set(src, src.startsWith('data:image/png') ? pdf.embedPng(src) : pdf.embedJpg(src));
        return images.get(src)!;
    };

    for (const f of fields) {
        const page = pages[f.page - 1];
        if (!page) continue;

        const box = page.getCropBox();
        const rotation = ((page.getRotation().angle % 360) + 360) % 360;
        const sideways = rotation === 90 || rotation === 270;
        // Displayed page size, in points.
        const W = sideways ? box.height : box.width;
        const H = sideways ? box.width : box.height;
        const bw = f.w * W;
        const bh = f.h * H;

        // Displayed point (top-left origin) → PDF user space, for each page rotation.
        const toPdf = (dx: number, dy: number) =>
            rotation === 90 ? { x: box.x + dy, y: box.y + dx }
            : rotation === 180 ? { x: box.x + box.width - dx, y: box.y + dy }
            : rotation === 270 ? { x: box.x + box.width - dy, y: box.y + box.height - dx }
            : { x: box.x + dx, y: box.y + box.height - dy };

        if (f.key === IMAGE_FIELD) {
            if (!f.src) continue;
            const image = await embed(f.src);
            // Fit inside the box, keeping its proportions; centred vertically.
            const s = Math.min(bw / image.width, bh / image.height);
            const dw = image.width * s;
            const dh = image.height * s;
            const left = f.x * W + (f.align === 'center' ? (bw - dw) / 2 : f.align === 'right' ? bw - dw : 0);
            const top = f.y * H + (bh - dh) / 2;
            // drawImage anchors at the image's bottom-left corner.
            page.drawImage(image, { ...toPdf(left, top + dh), width: dw, height: dh, rotate: degrees(rotation) });
            continue;
        }

        const raw = (f.key === TEXT_FIELD ? f.text : values[f.key]) ?? '';
        if (!raw.trim()) continue;
        const text = encodable(font, raw.trim());

        let size = Math.min(f.size, bh * 0.95);
        while (size > MIN_FONT && font.widthOfTextAtSize(text, size) > bw - 2) size -= 0.5;
        const tw = font.widthOfTextAtSize(text, size);

        // Baseline start in displayed coordinates.
        const dx = f.x * W + (f.align === 'center' ? (bw - tw) / 2 : f.align === 'right' ? bw - tw - 1 : 1);
        const dy = f.y * H + bh / 2 + size * 0.35;
        page.drawText(text, { ...toPdf(dx, dy), size, font, rotate: degrees(rotation) });
    }

    return pdf.save();
}

/** Longest side of an uploaded image, in pixels, and the largest data URL the server accepts. */
const IMAGE_MAX_PX = 1200;
const IMAGE_MAX_CHARS = 690_000;

/**
 * Read an uploaded image (PNG or JPG) as a data URL, scaled down so it stays small:
 * PNGs stay PNG (keeps transparency, e.g. a signature), JPGs are re-encoded as JPG.
 */
export async function imageFileToDataUrl(file: File): Promise<{ src: string; width: number; height: number }> {
    if (!/^image\/(png|jpeg)$/.test(file.type)) throw new Error('Use a PNG or JPG image.');
    if (file.size > 15 * 1024 * 1024) throw new Error('Images can be at most 15 MB.');

    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode().catch(() => { throw new Error('The image could not be read.'); });

        const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        let scale = Math.min(1, IMAGE_MAX_PX / Math.max(img.naturalWidth, img.naturalHeight));
        for (let attempt = 0; attempt < 6; attempt++, scale *= 0.75) {
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
            const ctx = canvas.getContext('2d')!;
            if (type === 'image/jpeg') {
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            const src = canvas.toDataURL(type, 0.85);
            if (src.length <= IMAGE_MAX_CHARS) return { src, width: canvas.width, height: canvas.height };
        }
        throw new Error('The image is too large. Use a smaller image.');
    } finally {
        URL.revokeObjectURL(url);
    }
}
