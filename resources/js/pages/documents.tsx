import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Braces, Copy, Download, FileUp, Loader2, MoreHorizontal, Pencil, Plus, SquareDashedMousePointer, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { BulkDeleteBar } from '@/components/data-table/bulk-delete-bar';
import { Field, PageHeader, StatusBadge } from '@/components/shared/misc';
import { ConfirmDialog } from '@/components/shared/pickers';
import { api, errorMessage, fieldErrors, openFile } from '@/lib/api';
import { date, fileSize, num } from '@/lib/format';
import { usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import type { DocumentTemplate, Paginated } from '@/lib/types';

export const TEMPLATE_ACCEPT = '.docx,.doc,.pdf,.xlsx,.xls,.odt';
const MAX_MB = 10;

/** File-type tag, highlighting templates that fill in client details. */
export function FileTypeBadge({ extension, fillable }: { extension: string; fillable?: boolean }) {
    return (
        <Badge variant="outline" className={fillable ? 'border-primary/40 text-primary' : 'text-muted-foreground'}>
            .{extension}{fillable && ' · fills details'}
        </Badge>
    );
}

/** Click-to-copy list of placeholders a .docx template can use. */
function PlaceholderHelp({ placeholders }: { placeholders: Record<string, string> }) {
    const copy = (key: string) => {
        navigator.clipboard?.writeText(`{{${key}}}`).then(() => toast.success(`Copied {{${key}}}`), () => undefined);
    };
    return (
        <details className="rounded-lg border bg-muted/30 px-3 py-2 text-sm">
            <summary className="flex cursor-pointer items-center gap-2 font-medium"><Braces className="size-4" /> Placeholders</summary>
            <p className="mt-2 text-xs text-muted-foreground">
                Type these in the Word file exactly as shown. For a PDF, place them on the page with the PDF editor (opens after upload). When a document is added to a client, they are replaced with that client record's details. Other file types are copied as-is.
            </p>
            <div className="mt-2 grid max-h-56 gap-1 overflow-y-auto sm:grid-cols-2">
                {Object.entries(placeholders).map(([k, desc]) => (
                    <button key={k} type="button" onClick={() => copy(k)} title="Copy" className="flex items-center justify-between gap-2 rounded-md px-2 py-1 text-left hover:bg-accent">
                        <span className="min-w-0">
                            <code className="text-xs">{`{{${k}}}`}</code>
                            <span className="block truncate text-xs text-muted-foreground">{desc}</span>
                        </span>
                        <Copy className="size-3.5 shrink-0 text-muted-foreground" />
                    </button>
                ))}
            </div>
        </details>
    );
}

function TemplateDialog({ open, onOpenChange, template, placeholders }: { open: boolean; onOpenChange: (o: boolean) => void; template: DocumentTemplate | null; placeholders: Record<string, string> }) {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const fileRef = useRef<HTMLInputElement>(null);
    const [v, setV] = useState({ name: '', description: '', is_active: true });
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setFile(null);
        setV(template ? { name: template.name, description: template.description ?? '', is_active: template.is_active } : { name: '', description: '', is_active: true });
    }, [open, template]);

    const save = useMutation({
        mutationFn: async () => {
            const local: Record<string, string> = {};
            if (!v.name.trim()) local.name = 'Name is required.';
            if (!template && !file) local.file = 'Choose a file to upload.';
            if (file && file.size > MAX_MB * 1024 * 1024) local.file = `Files can be at most ${MAX_MB} MB.`;
            if (Object.keys(local).length) { setErrors(local); throw new Error('local'); }

            const fd = new FormData();
            fd.append('name', v.name);
            fd.append('description', v.description);
            fd.append('is_active', v.is_active ? '1' : '0');
            if (file) fd.append('file', file);
            // Multipart updates are sent as POST with _method=PUT.
            if (template) fd.append('_method', 'PUT');
            return (await api.post<{ data: DocumentTemplate }>(template ? `/document-templates/${template.id}` : '/document-templates', fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data;
        },
        onSuccess: (saved) => {
            qc.invalidateQueries({ queryKey: ['document-templates'] });
            onOpenChange(false);
            // A newly uploaded PDF opens in the PDF editor to place its placeholders.
            if (saved.extension === 'pdf' && (!template || (file && !saved.placeholders.length))) {
                toast.success(template ? 'Document updated.' : 'Document added.', { description: 'Now place the placeholders on the PDF.' });
                navigate(`/documents/${saved.id}/pdf-editor`);
                return;
            }
            toast.success(template ? 'Document updated.' : 'Document added.', {
                description: saved.extension === 'pdf' && file
                    ? 'Placeholders were kept: check their positions in the PDF editor.'
                    : saved.fillable ? (saved.placeholders.length ? `Placeholders found: ${saved.placeholders.map((p) => `{{${p}}}`).join(', ')}` : 'No placeholders found: it will be copied as-is.') : undefined,
            });
        },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="max-h-[94vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{template ? 'Edit document' : 'New document'}</DialogTitle>
                    <DialogDescription>A template for client documents. Word (.docx) files and PDFs (placeholders placed in the PDF editor) are filled with the client's details.</DialogDescription>
                </DialogHeader>
                <form id="template-form" className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} noValidate>
                    <Field label="Name" required error={errors.name}>
                        <Input value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} maxLength={150} aria-invalid={!!errors.name} placeholder="e.g. Application Form" />
                    </Field>
                    <Field label="Description" error={errors.description}>
                        <Textarea rows={2} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} maxLength={500} />
                    </Field>
                    <Field label={template ? 'Replace file (optional)' : 'File'} required={!template} error={errors.file} hint={template ? `Current: ${template.file_name} (${fileSize(template.size)}). Client documents already made are not changed.` : `Word, PDF, Excel or OpenDocument · max ${MAX_MB} MB`}>
                        <input ref={fileRef} type="file" accept={TEMPLATE_ACCEPT} className="hidden" onChange={(e) => { setFile(e.target.files?.[0] ?? null); e.target.value = ''; }} />
                        <Button type="button" variant="outline" className="justify-start font-normal" onClick={() => fileRef.current?.click()} aria-invalid={!!errors.file}>
                            <FileUp className="size-4" /> {file ? `${file.name} (${fileSize(file.size)})` : 'Choose file…'}
                        </Button>
                    </Field>
                    <div className="flex items-center gap-2">
                        <Switch id="template-active" checked={v.is_active} onCheckedChange={(is_active) => setV({ ...v, is_active })} />
                        <Label htmlFor="template-active" className="text-sm font-normal">Active <span className="text-muted-foreground">(inactive documents can't be added to clients)</span></Label>
                    </div>
                    {template?.extension === 'pdf' && !file && (
                        <div className="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm">
                            <span className="text-muted-foreground">{template.placeholders.length ? `${template.placeholders.length} placeholder type${template.placeholders.length === 1 ? '' : 's'} placed on this PDF.` : 'No placeholders placed on this PDF yet.'}</span>
                            <Button type="button" size="sm" variant="outline" onClick={() => { onOpenChange(false); navigate(`/documents/${template.id}/pdf-editor`); }}>
                                <SquareDashedMousePointer className="size-4" /> Open PDF editor
                            </Button>
                        </div>
                    )}
                    <PlaceholderHelp placeholders={placeholders} />
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="template-form" disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} {template ? 'Save changes' : 'Add document'}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function DocumentsPage() {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const canManage = !!useAuth().user?.permissions.manage;
    const table = useTableState({ sort: 'name', direction: 'asc' });
    const query = usePaginated<DocumentTemplate>('document-templates', '/document-templates', table);
    const placeholders = (query.data as (Paginated<DocumentTemplate> & { placeholders?: Record<string, string> }) | undefined)?.placeholders ?? {};
    const selection = useRowSelection(table);
    const [dialog, setDialog] = useState<{ open: boolean; item: DocumentTemplate | null }>({ open: false, item: null });
    const [removing, setRemoving] = useState<DocumentTemplate | null>(null);

    const download = (t: DocumentTemplate) => openFile(`/api/document-templates/${t.id}/download`, t.file_name).catch((e) => toast.error(errorMessage(e)));

    const remove = useMutation({
        mutationFn: (t: DocumentTemplate) => api.delete(`/document-templates/${t.id}`),
        onSuccess: () => { toast.success('Document deleted.'); setRemoving(null); qc.invalidateQueries({ queryKey: ['document-templates'] }); },
        onError: (e) => { setRemoving(null); toast.error(errorMessage(e)); },
    });

    const columns: Column<DocumentTemplate>[] = [
        {
            key: 'name',
            header: 'Name',
            sortKey: 'name',
            cell: (t) => (
                <div className="min-w-[180px]">
                    <p className="font-medium">{t.name}</p>
                    {t.description && <p className="line-clamp-1 text-xs text-muted-foreground">{t.description}</p>}
                </div>
            ),
        },
        { key: 'type', header: 'Type', sortKey: 'extension', cell: (t) => <FileTypeBadge extension={t.extension} fillable={t.fillable} /> },
        {
            key: 'placeholders',
            header: 'Fills in',
            hideBelow: 'lg',
            cell: (t) => (t.fillable ? <span className="line-clamp-2 text-xs text-muted-foreground">{t.placeholders.length ? t.placeholders.map((p) => `{{${p}}}`).join(' ') : 'No placeholders'}</span> : <span className="text-xs text-muted-foreground">{t.extension === 'pdf' ? 'Copied as-is (no placeholders placed)' : 'Copied as-is'}</span>),
        },
        { key: 'status', header: 'Status', cell: (t) => <StatusBadge status={t.is_active ? 'active' : 'inactive'} /> },
        { key: 'used', header: 'Used', sortKey: 'used', align: 'right', hideBelow: 'md', cell: (t) => num(t.client_documents_count ?? 0) },
        { key: 'updated', header: 'Updated', sortKey: 'updated_at', hideBelow: 'md', cell: (t) => <span className="whitespace-nowrap text-muted-foreground">{date(t.updated_at)} · {fileSize(t.size)}</span> },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-10',
            cell: (t) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8" aria-label="Actions" onClick={(e) => e.stopPropagation()}><MoreHorizontal className="size-4" /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => download(t)}><Download className="size-4" /> Download</DropdownMenuItem>
                        {canManage && <DropdownMenuItem onClick={() => setDialog({ open: true, item: t })}><Pencil className="size-4" /> Edit</DropdownMenuItem>}
                        {canManage && t.extension === 'pdf' && <DropdownMenuItem onClick={() => navigate(`/documents/${t.id}/pdf-editor`)}><SquareDashedMousePointer className="size-4" /> Place placeholders (PDF editor)</DropdownMenuItem>}
                        {canManage && <DropdownMenuSeparator />}
                        {canManage && <DropdownMenuItem variant="destructive" onClick={() => setRemoving(t)}><Trash2 className="size-4" /> Delete</DropdownMenuItem>}
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Documents"
                description="Templates for client documents (forms, letters, checklists). Word files and PDFs with placeholders are filled with the client's details when added to a client."
                actions={canManage && <Button onClick={() => setDialog({ open: true, item: null })}><Plus className="size-4" /> New document</Button>}
            />
            <TableToolbar table={table} placeholder="Search name or description…">
                <FilterSelect table={table} name="kind" placeholder="Type" options={[{ value: 'fillable', label: 'Fills details (Word, PDF)' }, { value: 'other', label: 'Other (copied as-is)' }]} />
                <FilterSelect table={table} name="status" placeholder="Status" options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
            </TableToolbar>
            {canManage && <BulkDeleteBar selection={selection} url="/document-templates/bulk-delete" noun={['document', 'documents']} invalidate={['document-templates']} warning="Client documents already made from them are kept." />}
            <DataTable
                columns={columns}
                query={query}
                table={table}
                rowKey={(t) => t.id}
                selection={canManage ? selection : undefined}
                onRowClick={canManage ? (t) => setDialog({ open: true, item: t }) : undefined}
                emptyTitle="No documents yet"
                emptyDescription="Add a Word template (with {{placeholders}}), a PDF form (place placeholders in the PDF editor) or any file to reuse for clients."
            />
            <TemplateDialog open={dialog.open} template={dialog.item} placeholders={placeholders} onOpenChange={(o) => setDialog({ open: o, item: o ? dialog.item : null })} />
            <ConfirmDialog
                open={!!removing}
                onOpenChange={(o) => !o && setRemoving(null)}
                title="Delete this document?"
                description={`"${removing?.name}" will be deleted. Client documents already made from it are kept.`}
                onConfirm={() => removing && remove.mutate(removing)}
                loading={remove.isPending}
            />
        </div>
    );
}
