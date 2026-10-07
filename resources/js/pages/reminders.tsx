import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CircleCheck, Loader2, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { Field, PageHeader } from '@/components/shared/misc';
import { ClientPicker, ConfirmDialog, DatePicker } from '@/components/shared/pickers';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, fullName, label, todayISO } from '@/lib/format';
import { useMeta, usePaginated, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import type { Reminder } from '@/lib/types';
import { cn } from '@/lib/utils';

function ReminderDialog({ open, onOpenChange, reminder }: { open: boolean; onOpenChange: (o: boolean) => void; reminder: Reminder | null }) {
    const qc = useQueryClient();
    const meta = useMeta();
    const [v, setV] = useState({ type: 'follow_up', title: '', notes: '', due_date: todayISO() as string | null, client_id: null as number | null });
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setV(reminder ? { type: reminder.type, title: reminder.title, notes: reminder.notes ?? '', due_date: reminder.due_date, client_id: reminder.client_id } : { type: 'follow_up', title: '', notes: '', due_date: todayISO(), client_id: null });
    }, [open, reminder]);

    const save = useMutation({
        mutationFn: () => {
            const local: Record<string, string> = {};
            if (!v.title.trim()) local.title = 'Title is required.';
            if (!v.due_date) local.due_date = 'Due date is required.';
            if (Object.keys(local).length) { setErrors(local); throw new Error('local'); }
            const body = { ...v, notes: v.notes || null, policy_id: reminder?.policy_id ?? null };
            return reminder ? api.put(`/reminders/${reminder.id}`, body) : api.post('/reminders', body);
        },
        onSuccess: () => {
            toast.success('Reminder saved.');
            qc.invalidateQueries({ queryKey: ['reminders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            onOpenChange(false);
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
            <DialogContent dismissOnOutsideClick={false}>
                <DialogHeader><DialogTitle>{reminder ? 'Edit reminder' : 'New reminder'}</DialogTitle></DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Title" required error={errors.title} className="sm:col-span-2">
                        <Input value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} aria-invalid={!!errors.title} />
                    </Field>
                    <Field label="Type">
                        <Select value={v.type} onValueChange={(type) => setV({ ...v, type })}>
                            <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>{meta.data?.reminder_types.map((t) => <SelectItem key={t} value={t}>{label(t)}</SelectItem>)}</SelectContent>
                        </Select>
                    </Field>
                    <Field label="Due date" required error={errors.due_date}>
                        <DatePicker value={v.due_date} onChange={(due_date) => setV({ ...v, due_date })} invalid={!!errors.due_date} />
                    </Field>
                    <Field label="Client (optional)" className="sm:col-span-2">
                        <ClientPicker value={v.client_id} onChange={(client_id) => setV({ ...v, client_id })} allowClear />
                    </Field>
                    <Field label="Notes" className="sm:col-span-2">
                        <Textarea rows={3} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} />
                    </Field>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button onClick={() => save.mutate()} disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function RemindersPage() {
    const qc = useQueryClient();
    const meta = useMeta();
    const { user } = useAuth();
    const table = useTableState({ sort: 'due_date', direction: 'asc' });
    const state = table.params.state ?? 'open';
    const query = usePaginated<Reminder>('reminders', '/reminders', table, { state });
    const [dialog, setDialog] = useState<{ open: boolean; item: Reminder | null }>({ open: false, item: null });
    const [removing, setRemoving] = useState<Reminder | null>(null);

    // Completing only moves a reminder to the Completed tab (nothing is deleted); say so, with Undo.
    const setDone = useMutation({
        mutationFn: ({ r, done }: { r: Reminder; done: boolean; undo?: boolean }) => api.patch(`/reminders/${r.id}/complete`, { completed: done }),
        onSuccess: (_res, { r, done, undo }) => {
            qc.invalidateQueries({ queryKey: ['reminders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            if (undo) return;
            toast.success(done ? `"${r.title}" marked as done.` : `"${r.title}" reopened.`, {
                description: done ? 'Moved to the Completed tab.' : 'Moved back to Open.',
                action: { label: 'Undo', onClick: () => setDone.mutate({ r, done: !done, undo: true }) },
            });
        },
        onError: (e) => toast.error(errorMessage(e)),
    });
    const remove = useMutation({
        mutationFn: (r: Reminder) => api.delete(`/reminders/${r.id}`),
        onSuccess: () => { toast.success('Reminder deleted.'); setRemoving(null); qc.invalidateQueries({ queryKey: ['reminders'] }); },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const columns: Column<Reminder>[] = [
        { key: 'title', header: 'Reminder', cell: (r) => <div><p className={cn('font-medium', r.completed_at && 'text-muted-foreground line-through')}>{r.title}</p>{r.notes && <p className="line-clamp-1 text-xs text-muted-foreground">{r.notes}</p>}</div> },
        { key: 'type', header: 'Type', hideBelow: 'sm', cell: (r) => label(r.type) },
        { key: 'due', header: 'Due', cell: (r) => <span className={cn('whitespace-nowrap', r.is_overdue && 'font-medium text-destructive')}>{date(r.due_date)}{r.is_overdue && ' · overdue'}</span> },
        { key: 'client', header: 'Client / Policy', hideBelow: 'md', cell: (r) => <div className="text-sm">{r.client && <Link to={`/people/${r.client.id}`} className="hover:underline">{fullName(r.client)}</Link>}{r.policy_number && <Link to={`/clients/${r.policy_id}`} className="block text-xs text-muted-foreground hover:underline">{r.policy_number}</Link>}</div> },
        {
            key: 'actions', header: <span className="sr-only">Actions</span>, className: 'w-44 text-right', cell: (r) => (
                <span className="flex items-center justify-end gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-8"
                        onClick={() => setDone.mutate({ r, done: !r.completed_at })}
                        disabled={setDone.isPending && setDone.variables?.r.id === r.id}
                    >
                        {r.completed_at ? <RotateCcw className="size-3.5" /> : <CircleCheck className="size-3.5" />}
                        {r.completed_at ? 'Reopen' : 'Mark done'}
                    </Button>
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => setDialog({ open: true, item: r })} aria-label="Edit"><Pencil className="size-3.5" /></Button>
                    {user?.permissions.manage && <Button variant="ghost" size="icon" className="size-8 text-destructive" onClick={() => setRemoving(r)} aria-label="Delete"><Trash2 className="size-3.5" /></Button>}
                </span>
            ),
        },
    ];

    return (
        <div className="grid gap-5">
            <PageHeader title="Reminders" description="Follow-ups, premium collections, deliveries and other to-dos." actions={<Button onClick={() => setDialog({ open: true, item: null })}><Plus className="size-4" /> New reminder</Button>} />
            <Tabs value={state} onValueChange={(s) => table.set({ state: s === 'open' ? null : s })}>
                <TabsList>
                    <TabsTrigger value="open">Open</TabsTrigger>
                    <TabsTrigger value="due_today">Due today</TabsTrigger>
                    <TabsTrigger value="overdue">Overdue</TabsTrigger>
                    <TabsTrigger value="completed">Completed</TabsTrigger>
                </TabsList>
            </Tabs>
            <TableToolbar table={table} placeholder="Search reminders…">
                <FilterSelect table={table} name="type" placeholder="Type" options={(meta.data?.reminder_types ?? []).map((t) => ({ value: t, label: label(t) }))} />
            </TableToolbar>
            <DataTable columns={columns} query={query} table={table} rowKey={(r) => r.id} emptyTitle="Nothing here" emptyDescription="No reminders in this view." />
            <ReminderDialog open={dialog.open} reminder={dialog.item} onOpenChange={(o) => setDialog({ open: o, item: o ? dialog.item : null })} />
            <ConfirmDialog open={!!removing} onOpenChange={(o) => !o && setRemoving(null)} title="Delete reminder?" description={removing?.title} onConfirm={() => removing && remove.mutate(removing)} loading={remove.isPending} />
        </div>
    );
}
