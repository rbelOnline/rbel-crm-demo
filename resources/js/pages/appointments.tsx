import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Loader2, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { Field, PageHeader, StatusBadge } from '@/components/shared/misc';
import { ClientPicker, ConfirmDialog, DatePicker } from '@/components/shared/pickers';
import { api, errorMessage } from '@/lib/api';
import { applyServerErrors, nullify } from '@/lib/forms';
import { date, fullName, label, time12, todayISO } from '@/lib/format';
import { useMeta, usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { BulkDeleteBar } from '@/components/data-table/bulk-delete-bar';
import { RowDeleteButton } from '@/components/data-table/row-delete-button';
import { useAuth } from '@/hooks/use-auth';
import type { Appointment } from '@/lib/types';
import { labelStyle } from '@/lib/labels';
import { cn } from '@/lib/utils';

const schema = z.object({
    client_id: z.number({ message: 'Select a client.' }).nullable().refine((v) => !!v, 'Select a client.'),
    title: z.string().trim().min(1, 'Title is required.').max(150),
    description: z.string().max(5000).optional(),
    appointment_date: z.string().nullable().refine((v) => !!v, 'Date is required.'),
    appointment_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Enter a time (HH:MM).'),
    location: z.string().max(191).optional(),
    status: z.string().min(1),
    // Calendar colour label ('' = none).
    label: z.string(),
    notes: z.string().max(5000).optional(),
});
type Values = z.infer<typeof schema>;

/** Add / edit an appointment. Also used by the Calendar, which can pre-fill the date of a new one. */
export function AppointmentDialog({ open, onOpenChange, appointment, defaultDate, defaultTime }: { open: boolean; onOpenChange: (o: boolean) => void; appointment: Appointment | null; defaultDate?: string; defaultTime?: string }) {
    const qc = useQueryClient();
    const meta = useMeta();
    const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<Values>({ resolver: zodResolver(schema) });

    useEffect(() => {
        if (!open) return;
        reset(
            appointment
                ? { ...appointment, description: appointment.description ?? '', location: appointment.location ?? '', notes: appointment.notes ?? '', label: appointment.label ?? '' }
                : { client_id: null, title: '', description: '', appointment_date: defaultDate ?? todayISO(), appointment_time: defaultTime ?? '09:00', location: '', status: 'scheduled', label: '', notes: '' },
        );
    }, [open, appointment, defaultDate, defaultTime, reset]);

    const canDelete = !!useAuth().user?.permissions.manage && !!appointment;
    const [confirming, setConfirming] = useState(false);
    const remove = useMutation({
        mutationFn: () => api.delete(`/appointments/${appointment!.id}`),
        onSuccess: () => {
            toast.success('Appointment deleted.');
            qc.invalidateQueries({ queryKey: ['appointments'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setConfirming(false);
            onOpenChange(false);
        },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const save = useMutation({
        mutationFn: (v: Values) => (appointment ? api.put(`/appointments/${appointment.id}`, nullify(v)) : api.post('/appointments', nullify(v))),
        onSuccess: () => {
            toast.success(appointment ? 'Appointment updated.' : 'Appointment scheduled.');
            qc.invalidateQueries({ queryKey: ['appointments'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            onOpenChange(false);
        },
        onError: (e) => applyServerErrors(e, setError),
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{appointment ? 'Edit appointment' : 'New appointment'}</DialogTitle>
                </DialogHeader>
                <form id="appt-form" onSubmit={handleSubmit((v) => save.mutate(v))} className="grid gap-4 sm:grid-cols-2" noValidate>
                    <Field label="Client" required error={errors.client_id?.message} className="sm:col-span-2">
                        <Controller
                            control={control}
                            name="client_id"
                            render={({ field }) => <ClientPicker value={field.value} onChange={(id) => field.onChange(id)} placeholder="Select a client…" invalid={!!errors.client_id} />}
                        />
                    </Field>
                    <Field label="Title" htmlFor="title" required error={errors.title?.message} className="sm:col-span-2">
                        <Input id="title" {...register('title')} aria-invalid={!!errors.title} />
                    </Field>
                    <Field label="Date" required error={errors.appointment_date?.message}>
                        <Controller control={control} name="appointment_date" render={({ field }) => <DatePicker value={field.value} onChange={field.onChange} invalid={!!errors.appointment_date} />} />
                    </Field>
                    <Field label="Time" htmlFor="time" required error={errors.appointment_time?.message}>
                        <Input id="time" type="time" {...register('appointment_time')} aria-invalid={!!errors.appointment_time} />
                    </Field>
                    <Field label="Status" required>
                        <Controller
                            control={control}
                            name="status"
                            render={({ field }) => (
                                <Select value={field.value} onValueChange={field.onChange}>
                                    <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>{meta.data?.appointment_statuses.map((s) => <SelectItem key={s} value={s}>{label(s)}</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                        />
                    </Field>
                    <Field label="Label" htmlFor="appt_label" hint="Colour on the calendar.">
                        <Controller
                            control={control}
                            name="label"
                            render={({ field }) => (
                                <Select value={field.value || 'none'} onValueChange={(v) => field.onChange(v === 'none' ? '' : v)}>
                                    <SelectTrigger id="appt_label" className="w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            <span className="flex items-center gap-2"><span className={cn('size-2.5 rounded-full', labelStyle(null).dot)} /> No label</span>
                                        </SelectItem>
                                        {meta.data?.appointment_labels.map((l) => (
                                            <SelectItem key={l.key} value={l.key}>
                                                <span className="flex items-center gap-2"><span className={cn('size-2.5 rounded-full', labelStyle(l.key).dot)} /> {l.name}</span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        />
                    </Field>
                    <Field label="Location" htmlFor="location">
                        <Input id="location" {...register('location')} />
                    </Field>
                    <Field label="Description" htmlFor="description" className="sm:col-span-2">
                        <Textarea id="description" rows={2} {...register('description')} />
                    </Field>
                    <Field label="Notes" htmlFor="notes" className="sm:col-span-2">
                        <Textarea id="notes" rows={2} {...register('notes')} />
                    </Field>
                </form>
                <DialogFooter className="sm:justify-between">
                    {canDelete ? (
                        <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={() => setConfirming(true)}>
                            <Trash2 className="size-4" /> Delete
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" form="appt-form" disabled={save.isPending}>
                            {save.isPending && <Loader2 className="size-4 animate-spin" />} Save
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Delete this appointment?"
                description={`"${appointment?.title}" will be permanently removed.`}
                onConfirm={() => remove.mutate()}
                loading={remove.isPending}
            />
        </Dialog>
    );
}

export default function AppointmentsPage() {
    const meta = useMeta();
    const { user } = useAuth();
    const table = useTableState({ sort: 'appointment_date', direction: 'desc' });
    const query = usePaginated<Appointment>('appointments', '/appointments', table);
    const selection = useRowSelection(table);
    const canDelete = !!user?.permissions.manage;
    const [dialog, setDialog] = useState<{ open: boolean; item: Appointment | null }>({ open: false, item: null });

    const columns: Column<Appointment>[] = [
        {
            key: 'date',
            header: 'Date & time',
            sortKey: 'appointment_date',
            cell: (a) => (
                <div className="whitespace-nowrap">
                    <p className="font-medium">{date(a.appointment_date, 'EEE, MMM d, yyyy')}</p>
                    <p className="text-xs text-muted-foreground">{time12(a.appointment_time)}</p>
                </div>
            ),
        },
        {
            key: 'title',
            header: 'Title',
            sortKey: 'title',
            cell: (a) => (
                <span className="flex items-center gap-2 font-medium">
                    {a.label && <span className={cn('size-2.5 shrink-0 rounded-full', labelStyle(a.label).dot)} title={meta.data?.appointment_labels.find((l) => l.key === a.label)?.name} />}
                    {a.title}
                </span>
            ),
        },
        { key: 'client', header: 'Client', cell: (a) => (a.client ? <Link to={`/people/${a.client.id}`} className="hover:underline">{fullName(a.client)}</Link> : '—') },
        { key: 'location', header: 'Location', hideBelow: 'lg', cell: (a) => <span className="text-muted-foreground">{a.location ?? '—'}</span> },
        { key: 'status', header: 'Status', sortKey: 'status', cell: (a) => <StatusBadge status={a.status} /> },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: canDelete ? 'w-20' : 'w-10',
            cell: (a) => (
                <div className="flex items-center justify-end">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="size-8" aria-label="Actions"><MoreHorizontal className="size-4" /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => setDialog({ open: true, item: a })}><Pencil className="size-4" /> Edit</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {canDelete && (
                        <RowDeleteButton
                            url={`/appointments/${a.id}`}
                            label="Delete appointment"
                            title="Delete appointment?"
                            description={a.title}
                            success="Appointment deleted."
                            invalidate={['appointments', 'dashboard', 'client']}
                            onDeleted={() => selection.toggle(a.id, false)}
                        />
                    )}
                </div>
            ),
        },
    ];

    return (
        <div className="grid gap-5">
            <PageHeader title="Appointments" description="Meetings with leads and clients: reviews, presentations and deliveries." actions={<Button onClick={() => setDialog({ open: true, item: null })}><Plus className="size-4" /> New appointment</Button>} />
            <TableToolbar table={table} placeholder="Search title, lead or client…">
                <FilterSelect table={table} name="status" placeholder="Status" options={(meta.data?.appointment_statuses ?? []).map((s) => ({ value: s, label: label(s) }))} />
                <FilterSelect table={table} name="label" placeholder="Label" options={(meta.data?.appointment_labels ?? []).map((l) => ({ value: l.key, label: l.name }))} />
                <FilterSelect table={table} name="upcoming" placeholder="When" options={[{ value: '1', label: 'Upcoming only' }]} />
                <div className="w-[170px]"><DatePicker value={table.params.date_from} onChange={(v) => table.set({ date_from: v })} clearable placeholder="From date" /></div>
                <div className="w-[170px]"><DatePicker value={table.params.date_to} onChange={(v) => table.set({ date_to: v })} clearable placeholder="To date" /></div>
            </TableToolbar>
            {canDelete && <BulkDeleteBar selection={selection} url="/appointments/bulk-delete" noun={['appointment', 'appointments']} invalidate={['appointments', 'dashboard', 'client']} />}
            <DataTable columns={columns} query={query} table={table} rowKey={(a) => a.id} selection={canDelete ? selection : undefined} emptyTitle="No appointments" emptyDescription="Schedule a meeting with a lead or client to see it here." />
            <AppointmentDialog open={dialog.open} appointment={dialog.item} onOpenChange={(o) => setDialog({ open: o, item: o ? dialog.item : null })} />        </div>
    );
}
