import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Copy, Eye, ImagePlus, Loader2, Mail, MoreHorizontal, Pencil, Plus, Send } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { Field, PageHeader, StatusBadge } from '@/components/shared/misc';
import { ClientPicker } from '@/components/shared/pickers';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime, fullName, label } from '@/lib/format';
import { useDebounce, useMeta, usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { BulkDeleteBar } from '@/components/data-table/bulk-delete-bar';
import { RowDeleteButton } from '@/components/data-table/row-delete-button';
import { useAuth } from '@/hooks/use-auth';
import type { EmailLog, EmailTemplate, Paginated, Policy } from '@/lib/types';

interface Rendered {
    subject: string;
    html: string;
    text: string;
    unresolved: string[];
    recipient: string | null;
    uses_sample_data: boolean;
}

/** Rendered email body. `html` is escaped server-side (TemplateRenderer) before <br> insertion. */
function EmailPreview({ rendered, loading }: { rendered?: Rendered; loading?: boolean }) {
    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <div className="border-b bg-muted/40 px-4 py-2.5 text-sm">
                <p><span className="text-muted-foreground">To:</span> {rendered?.recipient ?? (rendered?.uses_sample_data ? 'Sample recipient' : '—')}</p>
                <p className="font-medium"><span className="font-normal text-muted-foreground">Subject:</span> {rendered?.subject ?? '…'}</p>
            </div>
            <div className="min-h-[160px] px-4 py-3 text-sm leading-relaxed">
                {loading && !rendered ? <Loader2 className="size-4 animate-spin" /> : <div dangerouslySetInnerHTML={{ __html: rendered?.html ?? '' }} />}
            </div>
            {!!rendered?.unresolved.length && (
                <div className="flex items-center gap-2 border-t bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    <AlertTriangle className="size-3.5" /> No value for: {rendered.unresolved.map((u) => `{{${u}}}`).join(', ')}
                </div>
            )}
        </div>
    );
}

function TemplateEditor({ open, onOpenChange, template }: { open: boolean; onOpenChange: (o: boolean) => void; template: EmailTemplate | null }) {
    const qc = useQueryClient();
    const meta = useMeta();
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const [v, setV] = useState({ name: '', subject: '', body: '', status: 'draft' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const debounced = useDebounce(v, 400);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setV(template ? { name: template.name, subject: template.subject, body: template.body, status: template.status } : { name: '', subject: '', body: 'Dear {{first_name}},\n\n\n\nWarm regards,\n{{advisor_name}}', status: 'draft' });
    }, [open, template]);

    // Live preview with sample data. Unsaved templates preview through any existing template id.
    const previewId = template?.id;
    const preview = useQuery({
        queryKey: ['template-live-preview', previewId, debounced.subject, debounced.body],
        queryFn: async () => (await api.post<{ data: Rendered }>(`/email-templates/${previewId}/preview`, { subject: debounced.subject || ' ', body: debounced.body || ' ' })).data.data,
        enabled: open && !!previewId,
        placeholderData: keepPreviousData,
    });

    /** Insert text at the caret (replacing any selection) and keep the caret after it. */
    const insertText = (token: string) => {
        const el = bodyRef.current;
        if (!el) return setV((s) => ({ ...s, body: s.body + token }));
        const start = el.selectionStart ?? v.body.length;
        const end = el.selectionEnd ?? v.body.length;
        const body = v.body.slice(0, start) + token + v.body.slice(end);
        setV({ ...v, body });
        requestAnimationFrame(() => { el.focus(); el.setSelectionRange(start + token.length, start + token.length); });
    };
    const insert = (key: string) => insertText(`{{${key}}}`);

    // Upload an image, then drop its {{image:ID}} token on its own line at the caret.
    const fileRef = useRef<HTMLInputElement>(null);
    const uploadImage = useMutation({
        mutationFn: async (file: File) => {
            const fd = new FormData();
            fd.append('image', file);
            return (await api.post<{ data: { id: number; token: string; name: string } }>('/email-images', fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data;
        },
        onSuccess: (img) => {
            const before = v.body.slice(0, bodyRef.current?.selectionStart ?? v.body.length);
            insertText(`${before && !before.endsWith('\n') ? '\n' : ''}${img.token}\n`);
            toast.success(`Image "${img.name}" inserted.`);
        },
        onError: (e) => toast.error(fieldErrors(e).image ?? errorMessage(e, 'The image could not be uploaded.')),
    });

    const save = useMutation({
        mutationFn: () => {
            const local: Record<string, string> = {};
            if (!v.name.trim()) local.name = 'Name is required.';
            if (!v.subject.trim()) local.subject = 'Subject is required.';
            if (!v.body.trim()) local.body = 'Body is required.';
            if (Object.keys(local).length) { setErrors(local); throw new Error('local'); }
            return template ? api.put(`/email-templates/${template.id}`, v) : api.post('/email-templates', v);
        },
        onSuccess: () => { toast.success('Template saved.'); qc.invalidateQueries({ queryKey: ['email-templates'] }); onOpenChange(false); },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="max-h-[94vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{template ? 'Edit template' : 'New template'}</DialogTitle>
                    <DialogDescription>Plain text with placeholders and images. Line breaks are kept; HTML is shown as text. An image appears where its {'{{image:…}}'} line is.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="grid content-start gap-4">
                        <div className="grid gap-4 sm:grid-cols-[1fr_140px]">
                            <Field label="Name" required error={errors.name}><Input value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} aria-invalid={!!errors.name} /></Field>
                            <Field label="Status">
                                <Select value={v.status} onValueChange={(status) => setV({ ...v, status })}>
                                    <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>{meta.data?.template_statuses.map((s) => <SelectItem key={s} value={s}>{label(s)}</SelectItem>)}</SelectContent>
                                </Select>
                            </Field>
                        </div>
                        <Field label="Subject" required error={errors.subject}><Input value={v.subject} onChange={(e) => setV({ ...v, subject: e.target.value })} aria-invalid={!!errors.subject} /></Field>
                        <Field label="Body" required error={errors.body}>
                            <Textarea ref={bodyRef} rows={12} className="font-mono text-sm" value={v.body} onChange={(e) => setV({ ...v, body: e.target.value })} aria-invalid={!!errors.body} />
                        </Field>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                ref={fileRef}
                                type="file"
                                accept="image/png,image/jpeg,image/gif,image/webp"
                                className="hidden"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) uploadImage.mutate(file);
                                    e.target.value = '';
                                }}
                            />
                            <Button type="button" variant="outline" size="sm" onClick={() => fileRef.current?.click()} disabled={uploadImage.isPending}>
                                {uploadImage.isPending ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-4" />} Insert image
                            </Button>
                            <span className="text-xs text-muted-foreground">JPG, PNG, GIF or WEBP, up to 2 MB. Embedded in the email when sent.</span>
                        </div>
                        <div>
                            <p className="mb-2 text-xs font-medium text-muted-foreground">Insert placeholder</p>
                            <div className="flex flex-wrap gap-1.5">
                                {Object.entries(meta.data?.placeholders ?? {}).map(([k, desc]) => (
                                    <button key={k} type="button" title={desc} onClick={() => insert(k)} className="rounded-md border bg-muted/50 px-2 py-1 font-mono text-xs hover:border-primary/50 hover:bg-accent">
                                        {`{{${k}}}`}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <div className="grid content-start gap-2">
                        <p className="text-sm font-medium">Preview <span className="font-normal text-muted-foreground">· sample data</span></p>
                        {previewId ? <EmailPreview rendered={preview.data} loading={preview.isLoading} /> : <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">Save the template once to enable the live preview.</p>}
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button onClick={() => save.mutate()} disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} Save template</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Preview a template for a real client/policy, then send it. Also used from the client page. */
export function SendEmailDialog({ open, onOpenChange, clientId, policyId: initialPolicyId, templateId, previewOnly }: { open: boolean; onOpenChange: (o: boolean) => void; clientId?: number; policyId?: number | null; templateId?: number; previewOnly?: boolean }) {
    const qc = useQueryClient();
    const { user } = useAuth();
    const [tpl, setTpl] = useState<number | null>(templateId ?? null);
    const [person, setPerson] = useState<number | null>(clientId ?? null);
    const [policyId, setPolicyId] = useState<string>('none');

    useEffect(() => {
        if (open) { setTpl(templateId ?? null); setPerson(clientId ?? null); setPolicyId(initialPolicyId ? String(initialPolicyId) : 'none'); }
    }, [open, templateId, clientId, initialPolicyId]);

    const templates = useQuery({
        queryKey: ['email-templates', 'active-list'],
        queryFn: async () => (await api.get<Paginated<EmailTemplate>>('/email-templates', { params: { per_page: 100 } })).data.data,
        enabled: open,
    });

    // Policies where the recipient is the owner OR the insured (two role-specific queries).
    const policies = useQuery({
        queryKey: ['recipient-policies', person],
        queryFn: async () => {
            const [owned, insured] = await Promise.all([
                api.get<Paginated<Policy>>('/policies', { params: { policy_owner_id: person, per_page: 50 } }),
                api.get<Paginated<Policy>>('/policies', { params: { policy_insured_id: person, per_page: 50 } }),
            ]);
            const map = new Map<number, Policy>();
            [...owned.data.data, ...insured.data.data].forEach((p) => map.set(p.id, p));
            return [...map.values()];
        },
        enabled: open && !!person,
    });

    const params = { client_id: person ?? undefined, policy_id: policyId === 'none' ? undefined : Number(policyId) };
    const preview = useQuery({
        queryKey: ['template-preview', tpl, params],
        queryFn: async () => (await api.post<{ data: Rendered }>(`/email-templates/${tpl}/preview`, params)).data.data,
        enabled: open && !!tpl,
    });

    const selected = templates.data?.find((t) => t.id === tpl);

    const send = useMutation({
        mutationFn: () => api.post(`/email-templates/${tpl}/send`, params),
        onSuccess: () => { toast.success('Email sent and logged.'); qc.invalidateQueries({ queryKey: ['email-logs'] }); onOpenChange(false); },
        onError: (e) => {
            qc.invalidateQueries({ queryKey: ['email-logs'] });
            // A failed send (502) returns the log entry; show the mail server's actual reason.
            const reason = (e as { response?: { data?: { data?: { error_message?: string } } } }).response?.data?.data?.error_message;
            toast.error('The email could not be sent. The failure was logged.', reason ? { description: reason, duration: 12_000 } : undefined);
            if (!reason) toast.error(errorMessage(e));
        },
    });

    const meta = useMeta();
    const deliveryOff = meta.data?.email_delivery_enabled === false;
    const canSend = !previewOnly && !deliveryOff && user?.permissions.manage && tpl && person && selected?.status === 'active' && preview.data?.recipient && !preview.data?.unresolved.length;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[94vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{previewOnly ? 'Preview email' : 'Send email'}</DialogTitle>
                    <DialogDescription>Choose a client (and optionally one of their policies) to see the final rendered email.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Template">
                        <Select value={tpl ? String(tpl) : undefined} onValueChange={(v) => setTpl(Number(v))}>
                            <SelectTrigger className="w-full"><SelectValue placeholder="Select template…" /></SelectTrigger>
                            <SelectContent>{templates.data?.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}{t.status !== 'active' && ` (${t.status})`}</SelectItem>)}</SelectContent>
                        </Select>
                    </Field>
                    <Field label="Recipient (client)">
                        <ClientPicker value={person} onChange={(id) => { setPerson(id); setPolicyId('none'); }} allowClear placeholder="Sample data" />
                    </Field>
                    <Field label="Policy (for policy placeholders)" className="sm:col-span-2">
                        <Select value={policyId} onValueChange={setPolicyId} disabled={!person}>
                            <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">No policy</SelectItem>
                                {policies.data?.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        {p.policy_number} · {p.product?.name} · {p.policy_owner_id === person ? (p.policy_insured_id === person ? 'owner & insured' : 'owner') : 'insured'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
                {tpl ? <EmailPreview rendered={preview.data} loading={preview.isFetching} /> : <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">Select a template to preview.</p>}
                {!previewOnly && selected && selected.status !== 'active' && <Alert><AlertDescription>Only active templates can be sent.</AlertDescription></Alert>}
                {!previewOnly && person && preview.data && !preview.data.recipient && <Alert variant="destructive"><AlertDescription>This client has no email address.</AlertDescription></Alert>}
                {!previewOnly && deliveryOff && (
                    <Alert variant="destructive">
                        <AlertDescription>Email delivery is not set up yet (the app is in log-only mode), so nothing can be sent. An administrator needs to add the Gmail sending account to the server settings.</AlertDescription>
                    </Alert>
                )}
                {!previewOnly && user && !deliveryOff && (
                    <p className="text-xs text-muted-foreground">
                        Sent as <span className="font-medium text-foreground">{user.name} via RBEL-CRM</span>; replies go to <span className="font-medium text-foreground">{user.email}</span>.
                    </p>
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                    {!previewOnly && (
                        <Button onClick={() => send.mutate()} disabled={!canSend || send.isPending}>
                            {send.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />} Send email
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function TemplatesTab() {
    const qc = useQueryClient();
    const meta = useMeta();
    const { user } = useAuth();
    const table = useTableState({ sort: 'name', direction: 'asc' });
    const query = usePaginated<EmailTemplate>('email-templates', '/email-templates', table);
    const selection = useRowSelection(table);
    const canDelete = !!user?.permissions.manage;
    const [editor, setEditor] = useState<{ open: boolean; item: EmailTemplate | null }>({ open: false, item: null });
    const [sendFor, setSendFor] = useState<{ id: number; previewOnly: boolean } | null>(null);

    const duplicate = useMutation({
        mutationFn: (t: EmailTemplate) => api.post(`/email-templates/${t.id}/duplicate`),
        onSuccess: () => { toast.success('Template duplicated as a draft.'); qc.invalidateQueries({ queryKey: ['email-templates'] }); },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const columns: Column<EmailTemplate>[] = [
        { key: 'name', header: 'Name', cell: (t) => <div><p className="font-medium">{t.name}</p><p className="line-clamp-1 text-xs text-muted-foreground">{t.subject}</p></div> },
        { key: 'status', header: 'Status', cell: (t) => <StatusBadge status={t.status} /> },
        { key: 'sent', header: 'Sent', align: 'right', hideBelow: 'sm', cell: (t) => t.logs_count ?? 0 },
        { key: 'updated', header: 'Updated', hideBelow: 'md', cell: (t) => <span className="text-muted-foreground">{dateTime(t.updated_at)}</span> },
        {
            key: 'actions', header: <span className="sr-only">Actions</span>, className: canDelete ? 'w-20' : 'w-10', cell: (t) => (
                <div className="flex items-center justify-end">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild><Button variant="ghost" size="icon" className="size-8" aria-label="Actions"><MoreHorizontal className="size-4" /></Button></DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => setEditor({ open: true, item: t })}><Pencil className="size-4" /> Edit</DropdownMenuItem>
                            <DropdownMenuItem onClick={() => setSendFor({ id: t.id, previewOnly: true })}><Eye className="size-4" /> Preview</DropdownMenuItem>
                            {user?.permissions.manage && <DropdownMenuItem onClick={() => setSendFor({ id: t.id, previewOnly: false })} disabled={t.status !== 'active'}><Send className="size-4" /> Send…</DropdownMenuItem>}
                            <DropdownMenuItem onClick={() => duplicate.mutate(t)}><Copy className="size-4" /> Duplicate</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {canDelete && <RowDeleteButton url={`/email-templates/${t.id}`} label="Delete template" title="Delete template?" description={`"${t.name}" will be deleted. Existing email logs are kept.`} success="Template deleted." invalidate={['email-templates']} onDeleted={() => selection.toggle(t.id, false)} />}
                </div>
            ),
        },
    ];

    return (
        <div className="grid gap-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex-1"><TableToolbar table={table} placeholder="Search templates…"><FilterSelect table={table} name="status" placeholder="Status" options={(meta.data?.template_statuses ?? []).map((s) => ({ value: s, label: label(s) }))} /></TableToolbar></div>
                <Button onClick={() => setEditor({ open: true, item: null })}><Plus className="size-4" /> New template</Button>
            </div>
            {canDelete && <BulkDeleteBar selection={selection} url="/email-templates/bulk-delete" noun={['template', 'templates']} invalidate={['email-templates']} warning="Emails already sent with them stay in the email log." />}
            <DataTable columns={columns} query={query} table={table} rowKey={(t) => t.id} selection={canDelete ? selection : undefined} onRowClick={(t) => setEditor({ open: true, item: t })} emptyTitle="No templates" />
            <TemplateEditor open={editor.open} template={editor.item} onOpenChange={(o) => setEditor({ open: o, item: o ? editor.item : null })} />
            <SendEmailDialog open={!!sendFor} onOpenChange={(o) => !o && setSendFor(null)} templateId={sendFor?.id} previewOnly={sendFor?.previewOnly} />        </div>
    );
}

function LogsTab() {
    const table = useTableState({ sort: 'created_at', direction: 'desc' });
    const query = usePaginated<EmailLog>('email-logs', '/email-logs', table);
    const columns: Column<EmailLog>[] = [
        { key: 'when', header: 'When', cell: (l) => <span className="whitespace-nowrap">{dateTime(l.sent_at ?? l.created_at)}</span> },
        { key: 'recipient', header: 'Recipient', cell: (l) => <div><p>{l.client ? fullName(l.client) : '—'}</p><p className="font-mono text-xs text-muted-foreground">{l.recipient}</p></div> },
        { key: 'subject', header: 'Subject', hideBelow: 'md', cell: (l) => <span className="line-clamp-1">{l.subject}</span> },
        { key: 'template', header: 'Template', hideBelow: 'lg', cell: (l) => l.template?.name ?? <span className="text-muted-foreground">Deleted</span> },
        { key: 'status', header: 'Status', cell: (l) => <div><StatusBadge status={l.status} />{l.error_message && <p className="mt-1 max-w-[220px] text-xs text-destructive">{l.error_message}</p>}</div> },
        { key: 'by', header: 'Sent by', hideBelow: 'xl', cell: (l) => l.sent_by ?? '—' },
    ];
    return (
        <div className="grid gap-4">
            <TableToolbar table={table} placeholder="Search subject…">
                <FilterSelect table={table} name="status" placeholder="Status" options={['pending', 'sent', 'failed'].map((s) => ({ value: s, label: label(s) }))} />
            </TableToolbar>
            <p className="text-xs text-muted-foreground">Recipient addresses are masked in the log. Message bodies are not stored.</p>
            <DataTable columns={columns} query={query} table={table} rowKey={(l) => l.id} emptyTitle="No emails sent yet" />
        </div>
    );
}

export default function EmailTemplatesPage() {
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') === 'logs' ? 'logs' : 'templates';
    return (
        <div className="grid gap-5">
            <PageHeader title="Email Templates" description="Reusable client emails with placeholders, preview and a sending log." />
            <Tabs value={tab} onValueChange={(t) => setParams(t === 'logs' ? { tab: 'logs' } : {}, { replace: true })}>
                <TabsList>
                    <TabsTrigger value="templates"><Mail className="size-4" /> Templates</TabsTrigger>
                    <TabsTrigger value="logs">Email log <Badge variant="secondary" className="ml-1">History</Badge></TabsTrigger>
                </TabsList>
                <TabsContent value="templates" className="mt-4">{tab === 'templates' && <TemplatesTab />}</TabsContent>
                <TabsContent value="logs" className="mt-4">{tab === 'logs' && <LogsTab />}</TabsContent>
            </Tabs>
        </div>
    );
}
