import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import { AlignCenter, AlignLeft, AlignRight, ArrowLeft, Copy, Download, Eye, FileText, GripVertical, Image as ImageIcon, Images, ImageUp, Loader2, MousePointerClick, Save, Search, Trash2, Type, ZoomIn, ZoomOut } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Separator } from '@/components/ui/separator';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { errorMessage } from '@/lib/api';
import { IMAGE_FIELD, imageFileToDataUrl, loadPdfjs, pdfPagesToImages, registerStampFontFace, saveBlob, stampPdf, STAMP_FONT_FAMILY, TEXT_FIELD, type PdfField } from '@/lib/pdf-fields';
import { cn } from '@/lib/utils';

/** A field that can be placed: a placeholder (with this record's value, in a client document). */
export interface PaletteItem {
    key: string;
    label: string;
    value?: string;
}

type Box = PdfField & { id: number };
type PageSize = { width: number; height: number };

const DRAG_TYPE = 'application/x-crm-pdf-field';
const SIZES = [6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24];
const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 2, 2.5];
/** Size of a new field, in points. */
const NEW_W = 170;
const NEW_H = 16;
const MIN_W = 0.01;
const MIN_H = 0.006;
/** Same limit as the server (images are stored with the fields). */
const MAX_IMAGES = 10;

const clamp = (v: number, min: number, max: number) => Math.min(Math.max(v, min), Math.max(min, max));
let nextId = 1;

/** One rendered page with its field boxes on top. */
function PdfPage({ pdf, index, size, zoom, armed, onPlace, onDropImage, onDeselect, children }: {
    pdf: PDFDocumentProxy; index: number; size: PageSize; zoom: number; armed: boolean;
    onPlace: (key: string, page: number, fx: number, fy: number) => void;
    onDropImage: (file: File, page: number, fx: number, fy: number) => void;
    onDeselect: () => void; children: ReactNode;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [dropping, setDropping] = useState(false);

    useEffect(() => {
        let task: RenderTask | null = null;
        let cancelled = false;
        (async () => {
            const page = await pdf.getPage(index + 1);
            const canvas = canvasRef.current;
            if (cancelled || !canvas) return;
            const viewport = page.getViewport({ scale: zoom * (window.devicePixelRatio || 1) });
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            task = page.render({ canvas, viewport });
            await task.promise.catch(() => undefined);
        })();
        return () => { cancelled = true; task?.cancel(); };
    }, [pdf, index, zoom]);

    const point = (e: React.MouseEvent) => {
        const r = e.currentTarget.getBoundingClientRect();
        return [(e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height] as const;
    };

    return (
        <div className="grid justify-items-center gap-1.5" data-page={index + 1}>
            <div
                className={cn('relative bg-white shadow-md ring-1 ring-black/10', armed && 'cursor-crosshair', dropping && 'ring-2 ring-primary')}
                style={{ width: size.width * zoom, height: size.height * zoom }}
                onClick={(e) => {
                    if (e.target !== e.currentTarget && !(e.target instanceof HTMLCanvasElement)) return;
                    if (armed) onPlace('', index + 1, ...point(e));
                    else onDeselect();
                }}
                onDragOver={(e) => { if (e.dataTransfer.types.includes(DRAG_TYPE) || e.dataTransfer.types.includes('Files')) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; setDropping(true); } }}
                onDragLeave={() => setDropping(false)}
                onDrop={(e) => {
                    setDropping(false);
                    e.preventDefault();
                    // An image file dragged from the computer is placed where it is dropped.
                    const file = e.dataTransfer.files[0];
                    if (file) return onDropImage(file, index + 1, ...point(e));
                    const key = e.dataTransfer.getData(DRAG_TYPE);
                    if (key) onPlace(key, index + 1, ...point(e));
                }}
            >
                <canvas ref={canvasRef} className="absolute inset-0 size-full" aria-label={`Page ${index + 1}`} />
                {children}
            </div>
            <span className="text-xs text-muted-foreground">Page {index + 1}</span>
        </div>
    );
}

/** A field box: drag to move, drag the corner to resize. */
function FieldBox({ box, text, empty, title, size, zoom, selected, onSelect, onChange }: {
    box: Box; text: string; empty: boolean; title: string; size: PageSize; zoom: number; selected: boolean;
    onSelect: () => void; onChange: (patch: Partial<PdfField>) => void;
}) {
    const startDrag = (e: React.PointerEvent, mode: 'move' | 'resize') => {
        if (e.button !== 0) return;
        e.preventDefault();
        e.stopPropagation();
        onSelect();
        const sx = e.clientX;
        const sy = e.clientY;
        const start = { ...box };
        const pw = size.width * zoom;
        const ph = size.height * zoom;
        const move = (ev: PointerEvent) => {
            const dx = (ev.clientX - sx) / pw;
            const dy = (ev.clientY - sy) / ph;
            if (!dx && !dy) return;
            onChange(mode === 'move'
                ? { x: clamp(start.x + dx, 0, 1 - start.w), y: clamp(start.y + dy, 0, 1 - start.h) }
                : { w: clamp(start.w + dx, MIN_W, 1 - start.x), h: clamp(start.h + dy, MIN_H, 1 - start.y) });
        };
        const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    };

    const fontPx = Math.min(box.size, box.h * size.height * 0.95) * zoom;

    return (
        <div
            role="button"
            tabIndex={-1}
            title={title}
            onPointerDown={(e) => startDrag(e, 'move')}
            onClick={(e) => e.stopPropagation()}
            className={cn(
                'absolute flex cursor-move touch-none items-center rounded-[2px] border border-dashed px-px leading-none whitespace-nowrap select-none',
                box.key === IMAGE_FIELD ? 'border-primary/60' : empty ? 'border-amber-500/80 bg-amber-400/15 text-amber-700' : 'border-primary/70 bg-primary/10 text-primary',
                selected && 'z-10 border-solid ring-2 ring-primary/40',
                box.align === 'center' && 'justify-center', box.align === 'right' && 'justify-end',
            )}
            style={{ left: `${box.x * 100}%`, top: `${box.y * 100}%`, width: `${box.w * 100}%`, height: `${box.h * 100}%`, fontSize: fontPx, fontFamily: `${STAMP_FONT_FAMILY}, Helvetica, Arial, sans-serif` }}
        >
            {box.key === IMAGE_FIELD && box.src ? (
                <img src={box.src} alt="" draggable={false} className="pointer-events-none size-full object-contain" style={{ objectPosition: box.align }} />
            ) : (
                <span className="min-w-0 truncate">{text}</span>
            )}
            {selected && (
                <span
                    onPointerDown={(e) => startDrag(e, 'resize')}
                    className="absolute -right-1 -bottom-1 size-2.5 cursor-se-resize rounded-sm border border-white bg-primary"
                    aria-label="Resize"
                />
            )}
        </div>
    );
}

/**
 * The PDF editor: the pages of a PDF with fields placed on them. Used for templates
 * (placeholders, filled per client later) and for client documents (`values` given:
 * the boxes show this record's details, and saving stamps them onto the PDF).
 */
export function PdfFieldEditor({ title, backTo, backLabel, loadSource, error, initialFields, palette, values, previewValues, onSave, hint }: {
    title?: string;
    backTo: string;
    backLabel: string;
    /** Fetches the unfilled PDF; null until the page's own data has loaded. */
    loadSource: (() => Promise<ArrayBuffer>) | null;
    error?: string | null;
    initialFields: PdfField[];
    palette: PaletteItem[];
    values?: Record<string, string>;
    previewValues: Record<string, string>;
    onSave: (fields: PdfField[], source: ArrayBuffer) => Promise<string | void>;
    hint: ReactNode;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const sourceRef = useRef<ArrayBuffer | null>(null);
    const textRef = useRef<HTMLTextAreaElement>(null);

    const [pdf, setPdf] = useState<PDFDocumentProxy | null>(null);
    const [pages, setPages] = useState<PageSize[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [zoom, setZoom] = useState(1);
    const [boxes, setBoxes] = useState<Box[]>([]);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [armed, setArmed] = useState<string | null>(null);
    const [dirty, setDirty] = useState(false);
    const [q, setQ] = useState('');
    const [previewing, setPreviewing] = useState(false);
    const [downloading, setDownloading] = useState(false);
    const [saving, setSaving] = useState(false);

    const labels = useMemo(() => Object.fromEntries(palette.map((p) => [p.key, p.label])), [palette]);
    const selected = boxes.find((b) => b.id === selectedId) ?? null;
    const fields = useCallback(() => boxes.map(({ id: _id, ...f }) => f), [boxes]);

    // Load the PDF once, with the saved fields.
    const startedRef = useRef(false);
    const initialRef = useRef(initialFields);
    initialRef.current = initialFields;
    useEffect(() => {
        if (!loadSource || startedRef.current) return;
        startedRef.current = true;
        (async () => {
            try {
                const source = await loadSource();
                sourceRef.current = source;
                const pdfjs = await loadPdfjs();
                // pdf.js takes ownership of the buffer it's given: pass a copy.
                const doc = await pdfjs.getDocument({ data: new Uint8Array(source.slice(0)) }).promise;
                const sizes: PageSize[] = [];
                for (let i = 1; i <= doc.numPages; i++) {
                    const vp = (await doc.getPage(i)).getViewport({ scale: 1 });
                    sizes.push({ width: vp.width, height: vp.height });
                }
                // Fit the widest page to the available width.
                const fit = ((scrollRef.current?.clientWidth ?? 900) - 48) / Math.max(...sizes.map((s) => s.width));
                setZoom(ZOOMS.reduce((best, z) => (z <= fit && z > best ? z : best), ZOOMS[0]));
                setPages(sizes);
                setBoxes(initialRef.current.filter((f) => f.page <= sizes.length).map((f) => ({ ...f, id: nextId++ })));
                setPdf(doc);
            } catch (e) {
                setLoadError(errorMessage(e, 'The PDF could not be opened. It may be damaged or password-protected.'));
            }
        })();
    }, [loadSource]);
    useEffect(() => () => { pdf?.loadingTask.destroy(); }, [pdf]);
    // Show box text in the same font the PDF gets (with the peso sign).
    useEffect(() => { registerStampFontFace().catch(() => undefined); }, []);

    const update = useCallback((boxId: number, patch: Partial<PdfField>) => {
        setBoxes((bs) => bs.map((b) => (b.id === boxId ? { ...b, ...patch } : b)));
        setDirty(true);
    }, []);

    const remove = useCallback((boxId: number) => {
        setBoxes((bs) => bs.filter((b) => b.id !== boxId));
        setSelectedId(null);
        setDirty(true);
    }, []);

    const duplicate = useCallback((box: Box) => {
        const copy: Box = { ...box, id: nextId++, y: clamp(box.y + box.h + 0.005, 0, 1 - box.h) };
        setBoxes((bs) => [...bs, copy]);
        setSelectedId(copy.id);
        setDirty(true);
    }, []);

    /** Add a field centred on a point of a page ('' = the armed field). */
    const place = (key: string, page: number, fx: number, fy: number) => {
        const k = key || armed;
        if (!k) return;
        const size = pages[page - 1];
        const last = boxes.at(-1);
        const isText = k === TEXT_FIELD;
        const w = Math.min((isText ? 100 : NEW_W) / size.width, 1);
        const h = Math.min(NEW_H / size.height, 1);
        const box: Box = {
            id: nextId++, key: k, page, w, h, x: clamp(fx - w / 2, 0, 1 - w), y: clamp(fy - h / 2, 0, 1 - h), size: last?.size ?? 10, align: 'left',
            ...(isText ? { text: 'Text' } : {}),
        };
        setBoxes((bs) => [...bs, box]);
        setSelectedId(box.id);
        setArmed(null);
        setDirty(true);
        if (isText) setTimeout(() => textRef.current?.select(), 0);
    };

    /**
     * Upload an image: place it on a page (centred on the point; by default the page in view),
     * or put it in an existing image box (`replaceId`), keeping the box width.
     */
    const addImage = async (file: File, at?: { page: number; fx: number; fy: number }, replaceId?: number) => {
        if (!replaceId && boxes.filter((b) => b.key === IMAGE_FIELD).length >= MAX_IMAGES) {
            toast.error(`A document can have at most ${MAX_IMAGES} images.`);
            return;
        }
        let image: Awaited<ReturnType<typeof imageFileToDataUrl>>;
        try {
            image = await imageFileToDataUrl(file);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'The image could not be read.');
            return;
        }
        const ratio = image.height / image.width;

        if (replaceId) {
            setBoxes((bs) => bs.map((b) => {
                if (b.id !== replaceId) return b;
                const size = pages[b.page - 1];
                const h = clamp((b.w * size.width * ratio) / size.height, MIN_H, 1 - b.y);
                return { ...b, src: image.src, h };
            }));
            setDirty(true);
            return;
        }

        const page = at?.page ?? currentPage();
        const size = pages[page - 1];
        if (!size) return;
        // About 2.5 inches wide, smaller if tall, never bigger than the image itself.
        let wPt = Math.min(180, size.width * 0.4, image.width);
        if (wPt * ratio > size.height * 0.4) wPt = (size.height * 0.4) / ratio;
        const w = wPt / size.width;
        const h = (wPt * ratio) / size.height;
        const fx = at?.fx ?? 0.5;
        const fy = at?.fy ?? 0.5;
        const box: Box = { id: nextId++, key: IMAGE_FIELD, src: image.src, page, w, h, x: clamp(fx - w / 2, 0, 1 - w), y: clamp(fy - h / 2, 0, 1 - h), size: 10, align: 'center' };
        setBoxes((bs) => [...bs, box]);
        setSelectedId(box.id);
        setArmed(null);
        setDirty(true);
    };
    const imageInputRef = useRef<HTMLInputElement>(null);
    const replaceIdRef = useRef<number | undefined>(undefined);
    const pickImage = (replaceId?: number) => {
        replaceIdRef.current = replaceId;
        imageInputRef.current?.click();
    };

    const save = useCallback(async () => {
        if (!pdf || !dirty || saving || !sourceRef.current) return;
        const blank = boxes.find((b) => b.key === TEXT_FIELD && !b.text?.trim());
        if (blank) {
            setSelectedId(blank.id);
            toast.error('Type the text for this text box, or remove it.');
            return;
        }
        setSaving(true);
        try {
            const message = await onSave(fields(), sourceRef.current.slice(0));
            setDirty(false);
            toast.success('Saved.', message ? { description: message } : undefined);
        } catch (e) {
            toast.error(e instanceof Error && !('isAxiosError' in e) ? e.message : errorMessage(e, 'The changes could not be saved.'));
        } finally {
            setSaving(false);
        }
    }, [pdf, dirty, saving, boxes, fields, onSave]);

    /** The page most visible in the viewer. */
    const currentPage = () => {
        const view = scrollRef.current?.getBoundingClientRect();
        let best = 1;
        let bestArea = -1;
        scrollRef.current?.querySelectorAll<HTMLElement>('[data-page]').forEach((el) => {
            const r = el.getBoundingClientRect();
            const area = view ? Math.max(0, Math.min(r.bottom, view.bottom) - Math.max(r.top, view.top)) * Math.max(0, Math.min(r.right, view.right) - Math.max(r.left, view.left)) : 0;
            if (area > bestArea) { bestArea = area; best = Number(el.dataset.page); }
        });
        return best;
    };

    const baseName = (title || 'document').replace(/[\\/:*?"<>|]+/g, '').trim() || 'document';

    /** Download the PDF as it looks now (fields filled as in Preview), or pages of it as images. */
    const download = async (what: 'pdf' | 'page' | 'all', type: 'image/png' | 'image/jpeg' = 'image/png') => {
        if (!sourceRef.current) return;
        setDownloading(true);
        try {
            const bytes = await stampPdf(sourceRef.current.slice(0), fields(), previewValues);
            if (what === 'pdf') {
                saveBlob(new Blob([bytes as BlobPart], { type: 'application/pdf' }), `${baseName}.pdf`);
                return;
            }
            const numbers = what === 'page' ? [currentPage()] : pages.map((_, i) => i + 1);
            const images = await pdfPagesToImages(bytes, numbers, type);
            const ext = type === 'image/png' ? 'png' : 'jpg';
            for (const [i, blob] of images.entries()) {
                saveBlob(blob, numbers.length === 1 && pages.length === 1 ? `${baseName}.${ext}` : `${baseName} - page ${numbers[i]}.${ext}`);
                // Browsers drop downloads started too close together.
                if (i < images.length - 1) await new Promise((r) => setTimeout(r, 300));
            }
            if (images.length > 1) toast.success(`Downloaded ${images.length} images.`, { description: 'If only one arrived, allow multiple downloads for this site.' });
        } catch {
            toast.error('The download could not be created.');
        } finally {
            setDownloading(false);
        }
    };

    const preview = async () => {
        if (!sourceRef.current) return;
        setPreviewing(true);
        try {
            const bytes = await stampPdf(sourceRef.current.slice(0), fields(), previewValues);
            const href = URL.createObjectURL(new Blob([bytes as BlobPart], { type: 'application/pdf' }));
            if (!window.open(href, '_blank', 'noopener')) toast.error('Allow pop-ups for this site to preview.');
            setTimeout(() => URL.revokeObjectURL(href), 60_000);
        } catch {
            toast.error('The preview could not be created.');
        } finally {
            setPreviewing(false);
        }
    };

    // Keyboard: Ctrl+S saves; Delete removes; arrows nudge (Shift = 10 pt); Ctrl+D duplicates; Esc cancels.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const mod = e.ctrlKey || e.metaKey;
            if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
            const t = e.target as HTMLElement;
            if (t.closest('input, textarea, select, [contenteditable="true"], [role="listbox"], [role="combobox"]')) return;
            if (e.key === 'Escape') { setArmed(null); setSelectedId(null); return; }
            if (!selected) return;
            const size = pages[selected.page - 1];
            const step = e.shiftKey ? 10 : 1;
            const nudge: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
            if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(selected.id); }
            else if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicate(selected); }
            else if (nudge[e.key] && size) {
                e.preventDefault();
                const [dx, dy] = nudge[e.key];
                update(selected.id, { x: clamp(selected.x + dx / size.width, 0, 1 - selected.w), y: clamp(selected.y + dy / size.height, 0, 1 - selected.h) });
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [save, selected, pages, remove, duplicate, update]);

    // Warn before losing unsaved changes.
    useEffect(() => {
        if (!dirty) return;
        const onBeforeUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);
    const confirmLeave = (e: React.MouseEvent) => {
        if (dirty && !window.confirm('You have unsaved changes. Leave without saving?')) e.preventDefault();
    };

    /** What a box shows: the text, this record's value, or the {{placeholder}}. */
    const display = (b: PdfField) => {
        if (b.key === TEXT_FIELD) return { text: b.text || 'Text', empty: !b.text?.trim() };
        const value = values?.[b.key];
        return value ? { text: value, empty: false } : { text: `{{${b.key}}}`, empty: !!values };
    };

    const zoomBy = (dir: 1 | -1) => setZoom((z) => ZOOMS[clamp(ZOOMS.indexOf(z) + dir, 0, ZOOMS.length - 1)] ?? 1);
    const used = useMemo(() => boxes.reduce<Record<string, number>>((acc, b) => ({ ...acc, [b.key]: (acc[b.key] ?? 0) + 1 }), {}), [boxes]);
    const shown = palette.filter((p) => `${p.key} ${p.label} ${p.value ?? ''}`.toLowerCase().includes(q.toLowerCase()));
    const failed = error ?? loadError;

    const paletteButton = (key: string, label: string, sub: ReactNode, icon: ReactNode) => (
        <button
            key={key}
            type="button"
            draggable={!!pdf}
            disabled={!pdf}
            onDragStart={(e) => { e.dataTransfer.setData(DRAG_TYPE, key); e.dataTransfer.effectAllowed = 'copy'; setArmed(null); }}
            onClick={() => setArmed(armed === key ? null : key)}
            className={cn('group flex w-full min-w-0 cursor-grab items-center gap-1.5 rounded-md px-1.5 py-1.5 text-left text-sm hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50', armed === key && 'bg-primary/10 ring-1 ring-primary')}
        >
            {icon}
            <span className="min-w-0 flex-1">
                <span className="block truncate">{label}</span>
                {sub}
            </span>
            {used[key] ? <Badge variant="secondary" className="shrink-0">{used[key]}</Badge> : armed === key && <MousePointerClick className="size-4 shrink-0 text-primary" />}
        </button>
    );

    return (
        <div className="grid gap-4">
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="min-w-0">
                    <Link to={backTo} onClick={confirmLeave} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> {backLabel}</Link>
                    {title ? <h1 className="mt-1 truncate text-2xl font-semibold tracking-tight">{title}</h1> : <Skeleton className="mt-1 h-8 w-64" />}
                    <p className="text-sm text-muted-foreground">
                        PDF editor · {boxes.length} field{boxes.length === 1 ? '' : 's'} · {saving ? 'Saving…' : dirty ? 'Unsaved changes' : 'All changes saved'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center rounded-md border">
                        <Button variant="ghost" size="icon" className="size-9" onClick={() => zoomBy(-1)} disabled={!pdf || zoom === ZOOMS[0]} aria-label="Zoom out"><ZoomOut className="size-4" /></Button>
                        <span className="w-12 text-center text-sm tabular-nums">{Math.round(zoom * 100)}%</span>
                        <Button variant="ghost" size="icon" className="size-9" onClick={() => zoomBy(1)} disabled={!pdf || zoom === ZOOMS.at(-1)} aria-label="Zoom in"><ZoomIn className="size-4" /></Button>
                    </div>
                    <Button variant="outline" onClick={preview} disabled={!pdf || previewing}>
                        {previewing ? <Loader2 className="size-4 animate-spin" /> : <Eye className="size-4" />} Preview
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" disabled={!pdf || downloading}>
                                {downloading ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />} Download
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-60">
                            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">With the fields filled, as in Preview</DropdownMenuLabel>
                            <DropdownMenuItem onClick={() => download('pdf')}><FileText className="size-4" /> PDF</DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => download('page', 'image/png')}><ImageIcon className="size-4" /> This page as image (PNG)</DropdownMenuItem>
                            <DropdownMenuItem onClick={() => download('page', 'image/jpeg')}><ImageIcon className="size-4" /> This page as image (JPG)</DropdownMenuItem>
                            {pages.length > 1 && (
                                <>
                                    <DropdownMenuItem onClick={() => download('all', 'image/png')}><Images className="size-4" /> All {pages.length} pages as images (PNG)</DropdownMenuItem>
                                    <DropdownMenuItem onClick={() => download('all', 'image/jpeg')}><Images className="size-4" /> All {pages.length} pages as images (JPG)</DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button onClick={save} disabled={!pdf || !dirty || saving}>
                        {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} Save
                    </Button>
                </div>
            </div>

            {failed ? (
                <Alert variant="destructive"><AlertDescription>{failed} <Link to={backTo} className="underline">{backLabel}</Link></AlertDescription></Alert>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
                    <div ref={scrollRef} className="max-h-[calc(100vh-11rem)] min-h-[60vh] overflow-auto rounded-xl bg-muted/60 p-4 sm:p-6">
                        {!pdf ? (
                            <div className="mx-auto grid max-w-2xl gap-3 bg-card p-8 shadow-sm"><Skeleton className="h-6 w-2/3" /><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-4/5" /><Skeleton className="h-64 w-full" /></div>
                        ) : (
                            <div className="grid w-max min-w-full justify-items-center gap-6">
                                {pages.map((size, i) => (
                                    <PdfPage key={i} pdf={pdf} index={i} size={size} zoom={zoom} armed={!!armed} onPlace={place} onDropImage={(file, page, fx, fy) => addImage(file, { page, fx, fy })} onDeselect={() => setSelectedId(null)}>
                                        {boxes.filter((b) => b.page === i + 1).map((b) => {
                                            const d = display(b);
                                            return (
                                                <FieldBox
                                                    key={b.id} box={b} text={d.text} empty={d.empty} size={size} zoom={zoom} selected={b.id === selectedId}
                                                    title={b.key === IMAGE_FIELD ? 'Image' : b.key === TEXT_FIELD ? 'Text' : `${labels[b.key] ?? b.key} ({{${b.key}}})${d.empty ? ': no value on record' : ''}`}
                                                    onSelect={() => setSelectedId(b.id)} onChange={(p) => update(b.id, p)}
                                                />
                                            );
                                        })}
                                    </PdfPage>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="grid gap-4 lg:sticky lg:top-20">
                        {selected && (
                            <Card className="gap-3 py-4">
                                <CardHeader className="px-4"><CardTitle className="text-sm">Selected field · page {selected.page}</CardTitle></CardHeader>
                                <CardContent className="grid gap-3 px-4">
                                    {selected.key === IMAGE_FIELD ? (
                                        <div className="flex items-center gap-3">
                                            {selected.src && <img src={selected.src} alt="" className="h-12 w-20 rounded border bg-[repeating-conic-gradient(#e5e7eb_0_25%,#fff_0_50%)] bg-[length:10px_10px] object-contain" />}
                                            <Button variant="outline" size="sm" onClick={() => pickImage(selected.id)}><ImageUp className="size-4" /> Replace image</Button>
                                        </div>
                                    ) : selected.key === TEXT_FIELD ? (
                                        <Textarea ref={textRef} rows={2} maxLength={500} value={selected.text ?? ''} onChange={(e) => update(selected.id, { text: e.target.value.replace(/[\r\n]+/g, ' ') })} placeholder="Text to write on the PDF" aria-label="Text" />
                                    ) : (
                                        <Select value={selected.key} onValueChange={(key) => update(selected.id, { key })}>
                                            <SelectTrigger className="w-full" aria-label="Field"><SelectValue /></SelectTrigger>
                                            <SelectContent className="max-h-72">
                                                {palette.map((p) => <SelectItem key={p.key} value={p.key}>{p.label}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    )}
                                    <div className="flex items-center gap-2">
                                        {selected.key !== IMAGE_FIELD && <Select value={String(selected.size)} onValueChange={(v) => update(selected.id, { size: Number(v) })}>
                                            <SelectTrigger size="sm" className="w-[88px]" aria-label="Font size"><SelectValue /></SelectTrigger>
                                            <SelectContent>{[...new Set([...SIZES, selected.size])].sort((a, b) => a - b).map((s) => <SelectItem key={s} value={String(s)}>{s} pt</SelectItem>)}</SelectContent>
                                        </Select>}
                                        <div className="flex rounded-md border" role="group" aria-label="Alignment">
                                            {([['left', AlignLeft], ['center', AlignCenter], ['right', AlignRight]] as const).map(([a, Icon]) => (
                                                <Button key={a} variant="ghost" size="icon" className={cn('size-8', selected.align === a && 'bg-accent')} aria-pressed={selected.align === a} aria-label={`Align ${a}`} onClick={() => update(selected.id, { align: a })}>
                                                    <Icon className="size-4" />
                                                </Button>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button variant="outline" size="sm" className="flex-1" onClick={() => duplicate(selected)}><Copy className="size-4" /> Duplicate</Button>
                                        <Button variant="outline" size="sm" className="flex-1 text-destructive hover:text-destructive" onClick={() => remove(selected.id)}><Trash2 className="size-4" /> Remove</Button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Drag to move, drag the corner to resize. Arrow keys nudge (Shift for bigger steps). {selected.key === IMAGE_FIELD ? 'The image keeps its proportions inside the box.' : 'Long values shrink to fit the box.'}
                                    </p>
                                </CardContent>
                            </Card>
                        )}

                        <Card className="gap-3 py-4">
                            <CardHeader className="px-4">
                                <CardTitle className="text-sm">Fields</CardTitle>
                                <p className="text-xs text-muted-foreground">
                                    {armed ? <span className="text-primary">Click on a page to place “{armed === TEXT_FIELD ? 'Text' : labels[armed]}”. Esc to cancel.</span> : 'Drag one onto the page, or click it and then click where it goes.'}
                                </p>
                            </CardHeader>
                            <CardContent className="grid gap-2 px-4">
                                <input
                                    ref={imageInputRef}
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="hidden"
                                    onChange={(e) => { const file = e.target.files?.[0]; if (file) addImage(file, undefined, replaceIdRef.current); e.target.value = ''; }}
                                />
                                {/* Same inset as the field list below, so icons and labels line up. */}
                                <div className="-mx-1 grid min-w-0">
                                    {paletteButton(TEXT_FIELD, 'Text', <span className="block truncate text-[11px] text-muted-foreground">Your own text, e.g. X for a checkbox</span>, <Type className="size-3.5 shrink-0 text-muted-foreground" />)}
                                    <button
                                        type="button"
                                        disabled={!pdf}
                                        onClick={() => pickImage()}
                                        className="flex w-full min-w-0 items-center gap-1.5 rounded-md px-1.5 py-1.5 text-left text-sm hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <ImageUp className="size-3.5 shrink-0 text-muted-foreground" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate">Upload image</span>
                                            <span className="block truncate text-[11px] text-muted-foreground">Signature, logo or stamp (PNG, JPG)</span>
                                        </span>
                                        {used[IMAGE_FIELD] ? <Badge variant="secondary" className="shrink-0">{used[IMAGE_FIELD]}</Badge> : null}
                                    </button>
                                </div>
                                <Separator />
                                <div className="relative">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search…" className="h-9 pl-8" />
                                </div>
                                <div className="-mx-1 grid max-h-[40vh] min-w-0 overflow-y-auto">
                                    {shown.map((p) => paletteButton(
                                        p.key,
                                        p.label,
                                        values ? (
                                            <span className={cn('block truncate text-[11px]', p.value ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-400')}>{p.value || 'No value on record'}</span>
                                        ) : (
                                            <code className="block truncate text-[11px] text-muted-foreground">{`{{${p.key}}}`}</code>
                                        ),
                                        <GripVertical className="size-3.5 shrink-0 text-muted-foreground" />,
                                    ))}
                                    {!shown.length && <p className="p-3 text-sm text-muted-foreground">No matching fields.</p>}
                                </div>
                                <Separator />
                                <p className="text-xs text-muted-foreground">{hint}</p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            )}
        </div>
    );
}
