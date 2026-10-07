import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Download, FilePenLine, FilePlus2, Files, FileUp, Loader2, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState, Field } from '@/components/shared/misc';
import { ConfirmDialog } from '@/components/shared/pickers';
import { FileTypeBadge } from '@/pages/documents';
import { api, errorMessage, fieldErrors, openFile } from '@/lib/api';
import { dateTime, fileSize } from '@/lib/format';
import { useAuth } from '@/hooks/use-auth';
import type { ClientDocument, DocumentTemplate, Paginated } from '@/lib/types';

const CLIENT_ACCEPT = '.pdf,.docx,.doc,.xlsx,.xls,.odt,.jpg,.jpeg,.png';
const MAX_MB = 10;

class PdfFillError extends Error {}

/** The in-app editor for a document: Word editor or PDF editor (null: download only). */
function editorPath(policyId: number, d: ClientDocument): string | null {
    if (d.editor === 'word') return `/clients/${policyId}/documents/${d.id}/edit`;
    if (d.editor === 'pdf') return `/clients/${policyId}/documents/${d.id}/pdf-editor`;
    return null;
}

function FilePicker({ file, onChange, invalid }: { file: File | null; onChange: (f: File | null) => void; invalid?: boolean }) {
    const ref = useRef<HTMLInputElement>(null);
    return (
        <>
            <input ref={ref} type="file" accept={CLIENT_ACCEPT} className="hidden" onChange={(e) => { onChange(e.target.files?.[0] ?? null); e.target.value = ''; }} />
            <Button type="button" variant="outline" className="w-full justify-start font-normal" onClick={() => ref.current?.click()} aria-invalid={invalid}>
                <FileUp className="size-4" /> <span className="truncate">{file ? `${file.name} (${fileSize(file.size)})` : 'Choose file…'}</span>
            </Button>
        </>
    );
}

/** Add a document: from a Documents-module template, or an uploaded file. */
function AddDocumentDialog({ open, onOpenChange, policyId }: { open: boolean; onOpenChange: (o: boolean) => void; policyId: number }) {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const [source, setSource] = useState<'template' | 'upload'>('template');
    const [templateId, setTemplateId] = useState('');
    const [name, setName] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setSource('template'); setTemplateId(''); setName(''); setFile(null); setErrors({});
    }, [open]);

    const templates = useQuery({
        queryKey: ['document-templates', 'active-options'],
        queryFn: async () => (await api.get<Paginated<DocumentTemplate>>('/document-templates', { params: { status: 'active', per_page: 100, sort: 'name' } })).data.data,
        enabled: open,
    });
    const chosen = templates.data?.find((t) => String(t.id) === templateId);

    /** A PDF template's placeholders are stamped here, with this record's details, before upload. */
    const filledPdf = async (id: number) => {
        const [template, values, source] = await Promise.all([
            api.get<{ data: DocumentTemplate }>(`/document-templates/${id}`).then((r) => r.data.data),
            api.get<{ data: { key: string; value: string }[] }>(`/policies/${policyId}/documents/fields`).then((r) => Object.fromEntries(r.data.data.map((f) => [f.key, f.value]))),
            api.get<ArrayBuffer>(`/api/document-templates/${id}/download`, { baseURL: '', responseType: 'arraybuffer' }).then((r) => r.data),
        ]);
        try {
            const { stampPdf } = await import('@/lib/pdf-fields');
            const bytes = await stampPdf(source, template.pdf_fields ?? [], values);
            return new Blob([bytes as BlobPart], { type: 'application/pdf' });
        } catch {
            throw new PdfFillError('The PDF template could not be filled. It may be damaged or password-protected.');
        }
    };

    const save = useMutation({
        mutationFn: async () => {
            const local: Record<string, string> = {};
            if (source === 'template' && !templateId) local.document_template_id = 'Choose a template.';
            if (source === 'upload' && !file) local.file = 'Choose a file to upload.';
            if (file && file.size > MAX_MB * 1024 * 1024) local.file = `Files can be at most ${MAX_MB} MB.`;
            if (Object.keys(local).length) { setErrors(local); throw new Error('local'); }

            const fd = new FormData();
            fd.append('source', source);
            if (name.trim()) fd.append('name', name.trim());
            if (source === 'template') {
                fd.append('document_template_id', templateId);
                if (chosen?.extension === 'pdf' && chosen.fillable) fd.append('file', await filledPdf(chosen.id), 'filled.pdf');
            } else if (file) fd.append('file', file);
            return (await api.post<{ data: ClientDocument }>(`/policies/${policyId}/documents`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data;
        },
        onSuccess: (doc) => {
            if (doc.unfilled.length) {
                toast.warning(`"${doc.name}" added, but some fields had no value`, { description: `Left ${doc.editor === 'pdf' ? 'blank' : 'as-is'}: ${doc.unfilled.map((u) => `{{${u}}}`).join(', ')}. Complete them in the ${doc.editor === 'pdf' ? 'PDF editor' : 'file'}, or fill in the client's details and add it again.`, duration: 12_000 });
            } else {
                toast.success(`"${doc.name}" added.`, doc.editor === 'pdf' ? { description: 'Place the client\'s details on it in the PDF editor.' } : undefined);
            }
            qc.invalidateQueries({ queryKey: ['client-documents', policyId] });
            qc.invalidateQueries({ queryKey: ['document-templates'] });
            onOpenChange(false);
            // A new PDF opens in the PDF editor.
            if (doc.editor === 'pdf') navigate(editorPath(policyId, doc)!);
        },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(e instanceof PdfFillError ? e.message : errorMessage(e));
        },
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add document</DialogTitle>
                    <DialogDescription>Create it from a template in Documents, or upload a file (e.g. a signed form or ID).</DialogDescription>
                </DialogHeader>
                <form id="client-doc-form" className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} noValidate>
                    <Tabs value={source} onValueChange={(s) => { setSource(s as 'template' | 'upload'); setErrors({}); }}>
                        <TabsList className="w-full">
                            <TabsTrigger value="template" className="flex-1">From template</TabsTrigger>
                            <TabsTrigger value="upload" className="flex-1">Upload file</TabsTrigger>
                        </TabsList>
                    </Tabs>

                    {source === 'template' ? (
                        <Field label="Template" required error={errors.document_template_id}>
                            {templates.isLoading ? (
                                <Skeleton className="h-9" />
                            ) : !templates.data?.length ? (
                                <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                    No active templates yet. Add one in <Link to="/documents" className="font-medium text-primary hover:underline">Documents</Link>.
                                </p>
                            ) : (
                                <Select value={templateId || undefined} onValueChange={setTemplateId}>
                                    <SelectTrigger className="w-full" aria-invalid={!!errors.document_template_id}><SelectValue placeholder="Choose a template…" /></SelectTrigger>
                                    <SelectContent>
                                        {templates.data.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.name} <span className="text-muted-foreground">· .{t.extension}{t.fillable ? ' · fills details' : ''}</span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            {chosen && (
                                <p className="text-xs text-muted-foreground">
                                    {chosen.fillable ? (chosen.placeholders.length ? `Filled with this client record's details (${chosen.placeholders.length} field${chosen.placeholders.length === 1 ? '' : 's'}).` : 'This Word file has no placeholders; it will be copied as-is.') : chosen.extension === 'pdf' ? 'Copied as-is (no placeholders were placed on this PDF in Documents).' : 'Copied as-is (only Word and PDF templates with placeholders are filled in).'}
                                </p>
                            )}
                        </Field>
                    ) : (
                        <Field label="File" required error={errors.file} hint={`PDF, Word, Excel, JPG or PNG · max ${MAX_MB} MB`}>
                            <FilePicker file={file} onChange={setFile} invalid={!!errors.file} />
                        </Field>
                    )}

                    <Field label="Name (optional)" error={errors.name} hint={source === 'template' ? 'Defaults to the template name.' : 'Defaults to the file name.'}>
                        <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={150} placeholder={source === 'template' ? chosen?.name ?? '' : file?.name.replace(/\.[^.]+$/, '') ?? ''} />
                    </Field>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="client-doc-form" disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} Add document</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Rename a document and optionally replace its file. */
function EditDocumentDialog({ document: doc, onOpenChange, policyId }: { document: ClientDocument | null; onOpenChange: (o: boolean) => void; policyId: number }) {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const [name, setName] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!doc) return;
        setName(doc.name); setFile(null); setErrors({});
    }, [doc]);

    const save = useMutation({
        mutationFn: async () => {
            if (!name.trim()) { setErrors({ name: 'Name is required.' }); throw new Error('local'); }
            const fd = new FormData();
            fd.append('_method', 'PUT');
            fd.append('name', name.trim());
            if (file) fd.append('file', file);
            return (await api.post<{ data: ClientDocument }>(`/policies/${policyId}/documents/${doc!.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data;
        },
        onSuccess: (saved) => {
            toast.success('Document updated.');
            qc.invalidateQueries({ queryKey: ['client-documents', policyId] });
            onOpenChange(false);
            // A newly uploaded PDF opens in the PDF editor.
            if (file && saved.editor === 'pdf') navigate(editorPath(policyId, saved)!);
        },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    return (
        <Dialog open={!!doc} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit document</DialogTitle>
                    <DialogDescription>Rename it, or replace the file (e.g. with the signed copy).</DialogDescription>
                </DialogHeader>
                <form id="edit-doc-form" className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} noValidate>
                    <Field label="Name" required error={errors.name}>
                        <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={150} aria-invalid={!!errors.name} />
                    </Field>
                    <Field label="Replace file (optional)" error={errors.file} hint={doc ? `Current: ${doc.file_name} (${fileSize(doc.size)})${doc.pdf_fields?.length ? '. Fields placed in the PDF editor are removed when the file is replaced.' : ''}` : undefined}>
                        <FilePicker file={file} onChange={setFile} invalid={!!errors.file} />
                    </Field>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="edit-doc-form" disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ClientDocumentsCard({ policyId }: { policyId: number }) {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const canDelete = !!useAuth().user?.permissions.manage;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<ClientDocument | null>(null);
    const [removing, setRemoving] = useState<ClientDocument | null>(null);

    const docs = useQuery({
        queryKey: ['client-documents', policyId],
        queryFn: async () => (await api.get<{ data: ClientDocument[] }>(`/policies/${policyId}/documents`)).data.data,
    });

    const download = (d: ClientDocument) => openFile(`/api/policies/${policyId}/documents/${d.id}/download`, d.file_name).catch((e) => toast.error(errorMessage(e)));

    const remove = useMutation({
        mutationFn: (d: ClientDocument) => api.delete(`/policies/${policyId}/documents/${d.id}`),
        onSuccess: () => { toast.success('Document deleted.'); setRemoving(null); qc.invalidateQueries({ queryKey: ['client-documents', policyId] }); qc.invalidateQueries({ queryKey: ['document-templates'] }); },
        onError: (e) => { setRemoving(null); toast.error(errorMessage(e)); },
    });

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div>
                    <CardTitle className="flex items-center gap-2"><Files className="size-4" /> Documents</CardTitle>
                    <CardDescription>Forms and files for this client. Word and PDF templates are filled with this record's details.</CardDescription>
                </div>
                <Button size="sm" onClick={() => setAdding(true)}><FilePlus2 className="size-4" /> Add document</Button>
            </CardHeader>
            <CardContent>
                {docs.isLoading ? (
                    <Skeleton className="h-24" />
                ) : !docs.data?.length ? (
                    <EmptyState icon={Files} title="No documents" description="Add one from a template in Documents, or upload a file." />
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Source</TableHead>
                                    <TableHead className="hidden sm:table-cell">Type</TableHead>
                                    <TableHead className="hidden md:table-cell">Added</TableHead>
                                    <TableHead className="w-10"><span className="sr-only">Actions</span></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {docs.data.map((d) => (
                                    <TableRow key={d.id}>
                                        <TableCell>
                                            {editorPath(policyId, d) ? (
                                                <Link to={editorPath(policyId, d)!} className="text-left font-medium hover:underline">{d.name}</Link>
                                            ) : (
                                                <button type="button" onClick={() => download(d)} className="text-left font-medium hover:underline">{d.name}</button>
                                            )}
                                            {d.edited_at && <p className="text-xs text-muted-foreground">Edited {dateTime(d.edited_at)}</p>}
                                            {d.unfilled.length > 0 && (
                                                <p className="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400" title={d.unfilled.map((u) => `{{${u}}}`).join(', ')}>
                                                    <AlertTriangle className="size-3" /> {d.unfilled.length} field{d.unfilled.length === 1 ? '' : 's'} not filled
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">{d.source === 'template' ? <span>Template{d.template ? `: ${d.template.name}` : ' (deleted)'}</span> : <span className="text-muted-foreground">Uploaded</span>}</TableCell>
                                        <TableCell className="hidden sm:table-cell"><FileTypeBadge extension={d.extension} /> <span className="ml-1 text-xs text-muted-foreground">{fileSize(d.size)}</span></TableCell>
                                        <TableCell className="hidden whitespace-nowrap text-sm text-muted-foreground md:table-cell">{dateTime(d.created_at)}{d.created_by && ` · ${d.created_by}`}</TableCell>
                                        <TableCell>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="size-8" aria-label="Actions"><MoreHorizontal className="size-4" /></Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {editorPath(policyId, d) && (
                                                        <DropdownMenuItem onClick={() => navigate(editorPath(policyId, d)!)}><FilePenLine className="size-4" /> {d.editor === "pdf" ? 'Fill in PDF editor' : 'Edit document'}</DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem onClick={() => download(d)}><Download className="size-4" /> Download</DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => setEditing(d)}><Pencil className="size-4" /> Rename / replace file</DropdownMenuItem>
                                                    {canDelete && <DropdownMenuSeparator />}
                                                    {canDelete && <DropdownMenuItem variant="destructive" onClick={() => setRemoving(d)}><Trash2 className="size-4" /> Delete</DropdownMenuItem>}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
            <AddDocumentDialog open={adding} onOpenChange={setAdding} policyId={policyId} />
            <EditDocumentDialog document={editing} onOpenChange={(o) => !o && setEditing(null)} policyId={policyId} />
            <ConfirmDialog open={!!removing} onOpenChange={(o) => !o && setRemoving(null)} title="Delete this document?" description={`"${removing?.name}" and its file will be permanently deleted.`} onConfirm={() => removing && remove.mutate(removing)} loading={remove.isPending} />
        </Card>
    );
}
