import { Extension, type Editor } from '@tiptap/react';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import type { Node as PMNode } from '@tiptap/pm/model';

export const PLACEHOLDER_RE = /\{\{\s*([a-z_]+)\s*\}\}/gi;

/** Convert a .docx (ArrayBuffer) to HTML for the editor. Images are embedded as data URLs. */
export async function docxToHtml(buffer: ArrayBuffer): Promise<string> {
    const mammoth = await import('mammoth');
    const result = await mammoth.convertToHtml(
        { arrayBuffer: buffer },
        {
            styleMap: ['u => u', 'strike => s'],
            convertImage: mammoth.images.imgElement(async (image) => ({ src: `data:${image.contentType};base64,${await image.readAsBase64String()}` })),
        },
    );
    return result.value || '<p></p>';
}

type Segment = { start: number; end: number; pos: number };

/**
 * Each textblock's text with a map back to document positions. Non-text inline
 * nodes (images, hard breaks) become a separator char so tokens never span them.
 */
function textblocks(doc: PMNode): { text: string; segments: Segment[] }[] {
    const blocks: { text: string; segments: Segment[] }[] = [];
    doc.descendants((node, pos) => {
        if (!node.isTextblock) return true;
        let text = '';
        const segments: Segment[] = [];
        node.forEach((child, offset) => {
            const piece = child.isText ? child.text ?? '' : '￼';
            if (child.isText) segments.push({ start: text.length, end: text.length + piece.length, pos: pos + 1 + offset });
            text += piece;
        });
        blocks.push({ text, segments });
        return false;
    });
    return blocks;
}

function toDocPos(segments: Segment[], index: number): number | null {
    for (const s of segments) if (index >= s.start && index <= s.end) return s.pos + (index - s.start);
    return null;
}

/** Placeholder names still present in the document. */
export function findPlaceholders(editor: Editor): string[] {
    const found = new Set<string>();
    for (const b of textblocks(editor.state.doc)) for (const m of b.text.matchAll(PLACEHOLDER_RE)) found.add(m[1].toLowerCase());
    return [...found];
}

/**
 * Replace every {{placeholder}} that has a value, keeping the formatting where the
 * placeholder starts (also when it spans differently formatted text).
 * Returns how many were filled and which had no value.
 */
export function fillPlaceholders(editor: Editor, values: Record<string, string>): { filled: number; missing: string[] } {
    const { state } = editor;
    const ranges: { from: number; to: number; value: string }[] = [];
    const missing = new Set<string>();

    for (const b of textblocks(state.doc)) {
        for (const m of b.text.matchAll(PLACEHOLDER_RE)) {
            const key = m[1].toLowerCase();
            const value = values[key];
            if (!value) {
                missing.add(key);
                continue;
            }
            const from = toDocPos(b.segments, m.index!);
            const to = toDocPos(b.segments, m.index! + m[0].length);
            if (from !== null && to !== null) ranges.push({ from, to, value });
        }
    }

    if (ranges.length) {
        const tr = state.tr;
        // Last to first, so earlier positions stay valid.
        for (const r of ranges.sort((a, b) => b.from - a.from)) {
            tr.insertText(r.value, r.from, r.to);
        }
        editor.view.dispatch(tr);
    }

    return { filled: ranges.length, missing: [...missing] };
}

/** Highlights unfilled {{placeholders}} in the page. */
export const PlaceholderHighlight = Extension.create({
    name: 'placeholderHighlight',
    addProseMirrorPlugins() {
        const build = (doc: PMNode) => {
            const decorations: Decoration[] = [];
            for (const b of textblocks(doc)) {
                for (const m of b.text.matchAll(PLACEHOLDER_RE)) {
                    const from = toDocPos(b.segments, m.index!);
                    const to = toDocPos(b.segments, m.index! + m[0].length);
                    if (from !== null && to !== null) decorations.push(Decoration.inline(from, to, { class: 'doc-placeholder', title: `No value yet: {{${m[1]}}}` }));
                }
            }
            return DecorationSet.create(doc, decorations);
        };
        return [
            new Plugin({
                key: new PluginKey('placeholderHighlight'),
                state: {
                    init: (_, { doc }) => build(doc),
                    apply: (tr, old) => (tr.docChanged ? build(tr.doc) : old),
                },
                props: {
                    decorations(state) {
                        return this.getState(state);
                    },
                },
            }),
        ];
    },
});

/** Open the page in a print window (Print, or "Save as PDF" in the print dialog). */
export function printDocument(title: string, html: string) {
    const w = window.open('', '_blank', 'width=900,height=1100');
    if (!w) return false;
    const escapedTitle = title.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]!);
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escapedTitle}</title><style>
        @page { size: A4; margin: 20mm; }
        body { font-family: Calibri, Carlito, 'Segoe UI', Arial, sans-serif; font-size: 11pt; line-height: 1.45; color: #111; }
        p { margin: 0 0 .6em; } h1 { font-size: 20pt; } h2 { font-size: 16pt; } h3 { font-size: 13pt; }
        table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #9ca3af; padding: 4px 6px; vertical-align: top; }
        img { max-width: 100%; }
    </style></head><body>${html}</body></html>`);
    w.document.close();
    w.focus();
    // Give embedded images a moment to render before the print dialog opens.
    setTimeout(() => w.print(), 400);
    return true;
}
