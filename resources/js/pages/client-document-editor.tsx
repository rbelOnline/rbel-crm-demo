import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { TableKit } from '@tiptap/extension-table';
import Image from '@tiptap/extension-image';
import { TextStyleKit } from '@tiptap/extension-text-style';
import Highlight from '@tiptap/extension-highlight';
import {
    AlignCenter, AlignJustify, AlignLeft, AlignRight, ArrowLeft, Bold, Braces, Download, Eraser, Highlighter, ImagePlus, Italic, List, ListOrdered,
    Loader2, Printer, Redo2, Save, Search, Strikethrough, Table as TableIcon, Underline, Undo2, Wand2,
} from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Separator } from '@/components/ui/separator';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { api, errorMessage, openFile } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { docxToHtml, fillPlaceholders, findPlaceholders, PlaceholderHighlight, printDocument } from '@/lib/doc-editor';
import type { ClientDocument } from '@/lib/types';
import { cn } from '@/lib/utils';

type Field = { key: string; label: string; value: string };

const FONTS = ['Calibri', 'Arial', 'Times New Roman', 'Georgia', 'Verdana'];
const SIZES = ['9', '10', '11', '12', '14', '16', '18', '24'];

function ToolButton({ label, active, disabled, onClick, children }: { label: string; active?: boolean; disabled?: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button type="button" variant="ghost" size="icon" className={cn('size-8', active && 'bg-accent text-accent-foreground')} onClick={onClick} disabled={disabled} aria-label={label} aria-pressed={active}>
                    {children}
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

function Toolbar({ editor }: { editor: Editor }) {
    const imageRef = useRef<HTMLInputElement>(null);
    // Re-render the toolbar when the selection or formatting changes.
    const s = useEditorState({
        editor,
        selector: ({ editor: e }) => ({
            bold: e.isActive('bold'), italic: e.isActive('italic'), underline: e.isActive('underline'), strike: e.isActive('strike'), highlight: e.isActive('highlight'),
            bullet: e.isActive('bulletList'), ordered: e.isActive('orderedList'), inTable: e.isActive('table'),
            left: e.isActive({ textAlign: 'left' }), center: e.isActive({ textAlign: 'center' }), right: e.isActive({ textAlign: 'right' }), justify: e.isActive({ textAlign: 'justify' }),
            block: e.isActive('heading', { level: 1 }) ? 'h1' : e.isActive('heading', { level: 2 }) ? 'h2' : e.isActive('heading', { level: 3 }) ? 'h3' : 'p',
            font: (e.getAttributes('textStyle').fontFamily as string | undefined)?.replace(/['"]/g, '').split(',')[0] ?? '',
            size: (e.getAttributes('textStyle').fontSize as string | undefined)?.replace('pt', '') ?? '',
            canUndo: e.can().undo(), canRedo: e.can().redo(),
        }),
    });
    const c = () => editor.chain().focus();

    const addImage = (file: File) => {
        if (!/^image\/(png|jpe?g|gif)$/.test(file.type)) return toast.error('Use a PNG, JPG or GIF image.');
        if (file.size > 2 * 1024 * 1024) return toast.error('Images can be at most 2 MB.');
        const reader = new FileReader();
        reader.onload = () => c().setImage({ src: String(reader.result) }).run();
        reader.readAsDataURL(file);
    };

    return (
        <div className="sticky top-16 z-20 flex flex-wrap items-center gap-0.5 rounded-xl border bg-card/95 p-1.5 shadow-sm backdrop-blur" role="toolbar" aria-label="Formatting">
            <ToolButton label="Undo (Ctrl+Z)" disabled={!s.canUndo} onClick={() => c().undo().run()}><Undo2 className="size-4" /></ToolButton>
            <ToolButton label="Redo (Ctrl+Y)" disabled={!s.canRedo} onClick={() => c().redo().run()}><Redo2 className="size-4" /></ToolButton>
            <Separator orientation="vertical" className="mx-1 h-6" />
            <Select value={s.block} onValueChange={(v) => (v === 'p' ? c().setParagraph().run() : c().setHeading({ level: Number(v[1]) as 1 | 2 | 3 }).run())}>
                <SelectTrigger size="sm" className="w-[118px]" aria-label="Text style"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="p">Normal text</SelectItem>
                    <SelectItem value="h1">Heading 1</SelectItem>
                    <SelectItem value="h2">Heading 2</SelectItem>
                    <SelectItem value="h3">Heading 3</SelectItem>
                </SelectContent>
            </Select>
            <Select value={s.font || undefined} onValueChange={(v) => c().setFontFamily(v).run()}>
                <SelectTrigger size="sm" className="w-[130px]" aria-label="Font"><SelectValue placeholder="Font" /></SelectTrigger>
                <SelectContent>{FONTS.map((f) => <SelectItem key={f} value={f} style={{ fontFamily: f }}>{f}</SelectItem>)}</SelectContent>
            </Select>
            <Select value={s.size || undefined} onValueChange={(v) => c().setFontSize(`${v}pt`).run()}>
                <SelectTrigger size="sm" className="w-[70px]" aria-label="Font size"><SelectValue placeholder="Size" /></SelectTrigger>
                <SelectContent>{SIZES.map((z) => <SelectItem key={z} value={z}>{z}</SelectItem>)}</SelectContent>
            </Select>
            <Separator orientation="vertical" className="mx-1 h-6" />
            <ToolButton label="Bold (Ctrl+B)" active={s.bold} onClick={() => c().toggleBold().run()}><Bold className="size-4" /></ToolButton>
            <ToolButton label="Italic (Ctrl+I)" active={s.italic} onClick={() => c().toggleItalic().run()}><Italic className="size-4" /></ToolButton>
            <ToolButton label="Underline (Ctrl+U)" active={s.underline} onClick={() => c().toggleUnderline().run()}><Underline className="size-4" /></ToolButton>
            <ToolButton label="Strikethrough" active={s.strike} onClick={() => c().toggleStrike().run()}><Strikethrough className="size-4" /></ToolButton>
            <ToolButton label="Highlight" active={s.highlight} onClick={() => c().toggleHighlight().run()}><Highlighter className="size-4" /></ToolButton>
            <Separator orientation="vertical" className="mx-1 h-6" />
            <ToolButton label="Align left" active={s.left} onClick={() => c().setTextAlign('left').run()}><AlignLeft className="size-4" /></ToolButton>
            <ToolButton label="Center" active={s.center} onClick={() => c().setTextAlign('center').run()}><AlignCenter className="size-4" /></ToolButton>
            <ToolButton label="Align right" active={s.right} onClick={() => c().setTextAlign('right').run()}><AlignRight className="size-4" /></ToolButton>
            <ToolButton label="Justify" active={s.justify} onClick={() => c().setTextAlign('justify').run()}><AlignJustify className="size-4" /></ToolButton>
            <Separator orientation="vertical" className="mx-1 h-6" />
            <ToolButton label="Bulleted list" active={s.bullet} onClick={() => c().toggleBulletList().run()}><List className="size-4" /></ToolButton>
            <ToolButton label="Numbered list" active={s.ordered} onClick={() => c().toggleOrderedList().run()}><ListOrdered className="size-4" /></ToolButton>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="ghost" size="icon" className={cn('size-8', s.inTable && 'bg-accent')} aria-label="Table"><TableIcon className="size-4" /></Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuItem onClick={() => c().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}>Insert table (3 × 3)</DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem disabled={!s.inTable} onClick={() => c().addRowAfter().run()}>Add row below</DropdownMenuItem>
                    <DropdownMenuItem disabled={!s.inTable} onClick={() => c().addColumnAfter().run()}>Add column right</DropdownMenuItem>
                    <DropdownMenuItem disabled={!s.inTable} onClick={() => c().mergeOrSplit().run()}>Merge / split cells</DropdownMenuItem>
                    <DropdownMenuItem disabled={!s.inTable} onClick={() => c().deleteRow().run()}>Delete row</DropdownMenuItem>
                    <DropdownMenuItem disabled={!s.inTable} onClick={() => c().deleteColumn().run()}>Delete column</DropdownMenuItem>
                    <DropdownMenuItem disabled={!s.inTable} variant="destructive" onClick={() => c().deleteTable().run()}>Delete table</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <input ref={imageRef} type="file" accept="image/png,image/jpeg,image/gif" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) addImage(f); e.target.value = ''; }} />
            <ToolButton label="Insert image" onClick={() => imageRef.current?.click()}><ImagePlus className="size-4" /></ToolButton>
            <ToolButton label="Clear formatting" onClick={() => c().unsetAllMarks().clearNodes().run()}><Eraser className="size-4" /></ToolButton>
        </div>
    );
}

/** Searchable list of this client's details; inserts the chosen value at the cursor. */
function InsertField({ fields, onInsert }: { fields: Field[]; onInsert: (f: Field) => void }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const shown = fields.filter((f) => `${f.label} ${f.key} ${f.value}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <Popover open={open} onOpenChange={(o) => { setOpen(o); if (!o) setQ(''); }}>
            <PopoverTrigger asChild>
                <Button variant="outline"><Braces className="size-4" /> Insert field</Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-[360px] p-2">
                <div className="relative mb-2">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search client details…" className="h-9 pl-8" />
                </div>
                <div className="max-h-80 overflow-y-auto" role="listbox">
                    {shown.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            role="option"
                            aria-selected={false}
                            onClick={() => { onInsert(f); setOpen(false); }}
                            className="flex w-full flex-col items-start rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                        >
                            <span className="font-medium">{f.label}</span>
                            <span className={cn('max-w-full truncate text-xs', f.value ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-400')}>{f.value || `No value on record: inserts {{${f.key}}}`}</span>
                        </button>
                    ))}
                    {!shown.length && <p className="p-3 text-sm text-muted-foreground">No matching fields.</p>}
                </div>
            </PopoverContent>
        </Popover>
    );
}

export default function ClientDocumentEditorPage() {
    const { policyId, documentId } = useParams();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [dirty, setDirty] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [ready, setReady] = useState(false);
    const [unfilled, setUnfilled] = useState<string[]>([]);

    const docs = useQuery({
        queryKey: ['client-documents', Number(policyId)],
        queryFn: async () => (await api.get<{ data: ClientDocument[] }>(`/policies/${policyId}/documents`)).data.data,
    });
    const doc = docs.data?.find((d) => String(d.id) === documentId);

    const fields = useQuery({
        queryKey: ['client-document-fields', Number(policyId)],
        queryFn: async () => (await api.get<{ data: Field[] }>(`/policies/${policyId}/documents/fields`)).data.data,
    });
    const values = useMemo(() => Object.fromEntries((fields.data ?? []).map((f) => [f.key, f.value])), [fields.data]);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ link: false, heading: { levels: [1, 2, 3] } }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TableKit.configure({ table: { resizable: true } }),
            Image.configure({ allowBase64: true, inline: true }),
            TextStyleKit,
            Highlight,
            PlaceholderHighlight,
        ],
        content: '',
        editorProps: { attributes: { class: 'focus:outline-none', spellcheck: 'true', 'aria-label': 'Document' } },
        onUpdate: ({ editor: e }) => {
            setDirty(true);
            setUnfilled(findPlaceholders(e));
        },
        immediatelyRender: false,
    });

    // Load: the saved editor copy, or else convert the .docx itself.
    useEffect(() => {
        if (!editor || !doc || ready) return;
        if (!doc.editable) {
            setLoadError('Only Word (.docx) documents can be edited here. Download it, or replace the file.');
            return;
        }
        (async () => {
            try {
                const content = (await api.get<{ data: { html: string | null } }>(`/policies/${policyId}/documents/${doc.id}/content`)).data.data;
                let html = content.html;
                if (!html) {
                    const file = await api.get<ArrayBuffer>(`/api/policies/${policyId}/documents/${doc.id}/download`, { baseURL: '', responseType: 'arraybuffer' });
                    html = await docxToHtml(file.data);
                }
                editor.commands.setContent(html, { emitUpdate: false });
                setUnfilled(findPlaceholders(editor));
                setReady(true);
            } catch (e) {
                setLoadError(errorMessage(e, 'The document could not be opened.'));
            }
        })();
    }, [editor, doc, ready, policyId]);

    const save = useMutation({
        mutationFn: async () => (await api.put<{ data: ClientDocument }>(`/policies/${policyId}/documents/${documentId}/content`, { html: editor!.getHTML() })).data.data,
        onSuccess: (saved) => {
            setDirty(false);
            toast.success('Document saved.', { description: saved.unfilled.length ? `${saved.unfilled.length} field(s) still to fill: ${saved.unfilled.map((u) => `{{${u}}}`).join(', ')}` : 'The Word file was updated.' });
            qc.invalidateQueries({ queryKey: ['client-documents', Number(policyId)] });
        },
        onError: (e) => toast.error(errorMessage(e, 'The document could not be saved.')),
    });

    const doSave = useCallback(() => {
        if (editor && ready && !save.isPending) save.mutate();
    }, [editor, ready, save]);

    // Ctrl/Cmd+S saves.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                doSave();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [doSave]);

    // Warn before losing unsaved changes when the tab is closed or reloaded.
    useEffect(() => {
        if (!dirty) return;
        const onBeforeUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);
    const confirmLeave = (e: React.MouseEvent) => {
        if (dirty && !window.confirm('You have unsaved changes. Leave without saving?')) e.preventDefault();
    };

    const autofill = () => {
        if (!editor) return;
        const { filled, missing } = fillPlaceholders(editor, values);
        setUnfilled(findPlaceholders(editor));
        if (!filled && !missing.length) toast.info('No {{placeholders}} in this document. Use "Insert field" to add client details at the cursor.');
        else if (missing.length) toast.warning(`Filled ${filled} field${filled === 1 ? '' : 's'}.`, { description: `No value on record for: ${missing.map((m) => `{{${m}}}`).join(', ')}. Fill these in the client's details, or type them in.`, duration: 10_000 });
        else toast.success(`Filled ${filled} field${filled === 1 ? '' : 's'} with the client's details.`);
    };

    const insertField = (f: Field) => {
        if (!editor) return;
        // Inserted as plain text (never parsed as HTML).
        editor.chain().focus().insertContent({ type: 'text', text: f.value || `{{${f.key}}}` }).run();
    };

    const backTo = `/clients/${policyId}`;

    if (docs.isSuccess && !doc) {
        return (
            <Alert variant="destructive">
                <AlertDescription>Document not found. <Link to={backTo} className="underline">Back to the client</Link></AlertDescription>
            </Alert>
        );
    }

    return (
        <div className="grid gap-4">
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="min-w-0">
                    <Link to={backTo} onClick={confirmLeave} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back to client</Link>
                    {doc ? <h1 className="mt-1 truncate text-2xl font-semibold tracking-tight">{doc.name}</h1> : <Skeleton className="mt-1 h-8 w-64" />}
                    <p className="text-sm text-muted-foreground">
                        {save.isPending ? 'Saving…' : dirty ? 'Unsaved changes' : doc?.edited_at ? `Saved ${dateTime(doc.edited_at)}` : 'Not edited yet'}
                        {unfilled.length > 0 && <span className="ml-2 text-amber-700 dark:text-amber-400">· {unfilled.length} field{unfilled.length === 1 ? '' : 's'} to fill</span>}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={autofill} disabled={!ready || fields.isLoading}>
                        <Wand2 className="size-4" /> Autofill client data
                    </Button>
                    <InsertField fields={fields.data ?? []} onInsert={insertField} />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" disabled={!ready} aria-label="Print or download"><Printer className="size-4" /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => editor && !printDocument(doc?.name ?? 'Document', editor.getHTML()) && toast.error('Allow pop-ups for this site to print.')}>
                                <Printer className="size-4" /> Print / Save as PDF
                            </DropdownMenuItem>
                            <DropdownMenuItem disabled={dirty} onClick={() => doc && openFile(`/api/policies/${policyId}/documents/${doc.id}/download`, doc.file_name).catch((e) => toast.error(errorMessage(e)))}>
                                <Download className="size-4" /> Download Word file{dirty && ' (save first)'}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button onClick={doSave} disabled={!ready || !dirty || save.isPending}>
                        {save.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} Save
                    </Button>
                </div>
            </div>

            {loadError ? (
                <Alert variant="destructive">
                    <AlertDescription>{loadError} <button type="button" className="underline" onClick={() => navigate(backTo)}>Back to the client</button></AlertDescription>
                </Alert>
            ) : (
                <>
                    {editor && ready && <Toolbar editor={editor} />}
                    <div className="rounded-xl bg-muted/60 p-3 sm:p-8">
                        {!ready && <div className="doc-page"><Skeleton className="mb-3 h-6 w-2/3" /><Skeleton className="mb-2 h-4 w-full" /><Skeleton className="mb-2 h-4 w-full" /><Skeleton className="h-4 w-4/5" /></div>}
                        <div className={cn('doc-page', !ready && 'hidden')}>
                            <EditorContent editor={editor} />
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Saving updates the Word file. Highlighted <span className="doc-placeholder rounded px-1">{'{{fields}}'}</span> have no value yet: use Autofill, or type over them. Complex Word layouts (text boxes, columns, headers/footers) may be simplified when edited here.
                    </p>
                </>
            )}
        </div>
    );
}
