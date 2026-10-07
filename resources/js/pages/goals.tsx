import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Goal as GoalIcon, Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { EmptyState, Field, KpiCard, PageHeader, StatusBadge } from '@/components/shared/misc';
import { ConfirmDialog, DatePicker } from '@/components/shared/pickers';
import { MoneyInput } from '@/components/shared/money-input';
import { api, cleanParams, errorMessage } from '@/lib/api';
import { applyServerErrors } from '@/lib/forms';
import { date, label, money, num, pct, todayISO } from '@/lib/format';
import { useDebounce, useMeta, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import type { Goal, Paginated } from '@/lib/types';
import { cn } from '@/lib/utils';

const amount = z.string().trim().refine((v) => /^\d+(\.\d{1,2})?$/.test(v), 'Enter a valid amount.');
const schema = z
    .object({
        title: z.string().trim().min(1, 'Title is required.').max(150),
        description: z.string().max(5000).optional(),
        target_amount: amount.refine((v) => Number(v) > 0, 'Target must be greater than 0.'),
        start_date: z.string().nullable().refine((v) => !!v, 'Date from is required.'),
        target_date: z.string().nullable().refine((v) => !!v, 'Date to is required.'),
        status: z.string().min(1),
    })
    .refine((v) => !v.start_date || !v.target_date || v.target_date >= v.start_date, { path: ['target_date'], message: 'Date to cannot be before Date from.' });
type Values = z.infer<typeof schema>;

/** How the server derives a goal's current amount (GoalProgressService). */
const CURRENT_SOURCE = 'APE of all client records (policies) issued from Date from to Date to, excluding postponed';

/** "Jan 1 – Mar 31, 2026", or with both years when they differ. */
function rangeLabel(from: string, to: string) {
    return from.slice(0, 4) === to.slice(0, 4) ? `${date(from, 'MMM d')} – ${date(to)}` : `${date(from)} – ${date(to)}`;
}

function GoalDialog({ open, onOpenChange, goal }: { open: boolean; onOpenChange: (o: boolean) => void; goal: Goal | null }) {
    const qc = useQueryClient();
    const meta = useMeta();
    const { register, control, handleSubmit, reset, setError, watch, formState: { errors } } = useForm<Values>({ resolver: zodResolver(schema) });

    useEffect(() => {
        if (!open) return;
        reset(
            goal
                ? { title: goal.title, description: goal.description ?? '', target_amount: String(goal.target_amount), start_date: goal.start_date, target_date: goal.target_date, status: goal.status }
                : { title: '', description: '', target_amount: '', start_date: todayISO(), target_date: null, status: 'not_started' },
        );
    }, [open, goal, reset]);

    const startDate = watch('start_date');
    const endDate = watch('target_date');

    const save = useMutation({
        mutationFn: (v: Values) => {
            const body = { ...v, target_amount: Number(v.target_amount), description: v.description || null };
            return goal ? api.put(`/goals/${goal.id}`, body) : api.post('/goals', body);
        },
        onSuccess: () => {
            toast.success(goal ? 'Goal updated.' : 'Goal created.');
            qc.invalidateQueries({ queryKey: ['goals'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            onOpenChange(false);
        },
        onError: (e) => applyServerErrors(e, setError),
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-xl">
                <DialogHeader><DialogTitle>{goal ? 'Edit goal' : 'New goal'}</DialogTitle></DialogHeader>
                <form id="goal-form" onSubmit={handleSubmit((v) => save.mutate(v))} className="grid gap-4 sm:grid-cols-2" noValidate>
                    <Field label="Title" htmlFor="title" required error={errors.title?.message} className="sm:col-span-2">
                        <Input id="title" {...register('title')} aria-invalid={!!errors.title} />
                    </Field>
                    <Field label="Target amount (₱)" htmlFor="target" required error={errors.target_amount?.message}>
                        <Controller control={control} name="target_amount" render={({ field }) => <MoneyInput id="target" value={field.value} onChange={field.onChange} onBlur={field.onBlur} placeholder="0.00" aria-invalid={!!errors.target_amount} />} />
                    </Field>
                    <Field label="Current amount (automatic)" htmlFor="current">
                        <Input id="current" value={goal && goal.start_date === startDate && goal.target_date === endDate ? money(goal.current_amount) : 'Calculated on save'} readOnly disabled />
                    </Field>
                    <p className="-mt-2 text-xs text-muted-foreground sm:col-span-2">
                        Current = {CURRENT_SOURCE}. It updates automatically whenever policies change.
                    </p>
                    <Field label="Date from" required error={errors.start_date?.message}>
                        <Controller control={control} name="start_date" render={({ field }) => <DatePicker value={field.value} onChange={field.onChange} invalid={!!errors.start_date} />} />
                    </Field>
                    <Field label="Date to" required error={errors.target_date?.message}>
                        <Controller control={control} name="target_date" render={({ field }) => <DatePicker value={field.value} onChange={field.onChange} invalid={!!errors.target_date} />} />
                    </Field>
                    <Field label="Status" required error={errors.status?.message}>
                        <Controller control={control} name="status" render={({ field }) => (
                            <Select value={field.value} onValueChange={field.onChange}>
                                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>{meta.data?.goal_statuses.map((s) => <SelectItem key={s} value={s}>{label(s)}</SelectItem>)}</SelectContent>
                            </Select>
                        )} />
                    </Field>
                    <Field label="Description" htmlFor="desc" className="sm:col-span-2">
                        <Textarea id="desc" rows={2} {...register('description')} />
                    </Field>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="goal-form" disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function GoalsPage() {
    const qc = useQueryClient();
    const meta = useMeta();
    const { user } = useAuth();
    const table = useTableState({ sort: 'target_date', direction: 'asc' });
    const search = useDebounce(table.params.search ?? '');
    const [dialog, setDialog] = useState<{ open: boolean; item: Goal | null }>({ open: false, item: null });
    const [removing, setRemoving] = useState<Goal | null>(null);

    const params = cleanParams({ ...table.params, search, sort: table.sort, direction: table.direction });
    const query = useQuery({
        queryKey: ['goals', params],
        queryFn: async () => (await api.get<Paginated<Goal> & { summary: Record<string, number> }>('/goals', { params })).data,
        placeholderData: keepPreviousData,
    });

    const remove = useMutation({
        mutationFn: (g: Goal) => api.delete(`/goals/${g.id}`),
        onSuccess: () => { toast.success('Goal deleted.'); setRemoving(null); qc.invalidateQueries({ queryKey: ['goals'] }); },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const s = query.data?.summary;
    const meta2 = query.data?.meta;

    return (
        <div className="grid gap-5">
            <PageHeader title="Goals" description="APE targets over a date range, counted automatically from your client records." actions={<Button onClick={() => setDialog({ open: true, item: null })}><Plus className="size-4" /> New goal</Button>} />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <KpiCard title="Open goals" value={num(s?.open ?? 0)} icon={GoalIcon} />
                <KpiCard title="Average progress" value={pct(s?.avg_progress ?? 0, 0)} hint="Across open goals" icon={GoalIcon} tone="success" />
                <KpiCard title="Achieved" value={num(s?.achieved ?? 0)} icon={GoalIcon} tone="muted" />
                <KpiCard title="Past target date" value={num(s?.overdue ?? 0)} hint="Open and overdue" icon={CalendarClock} tone="danger" />
            </div>

            <TableToolbar table={table} placeholder="Search goals…">
                <FilterSelect table={table} name="status" placeholder="Status" options={(meta.data?.goal_statuses ?? []).map((v) => ({ value: v, label: label(v) }))} />
                <FilterSelect table={table} name="sort" placeholder="Sort" options={[{ value: 'target_date', label: 'Date to' }, { value: 'progress', label: 'Progress' }, { value: 'target_amount', label: 'Target amount' }, { value: 'title', label: 'Title' }]} />
            </TableToolbar>

            {query.isLoading ? (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-44 rounded-xl" />)}</div>
            ) : !query.data?.data.length ? (
                <EmptyState icon={GoalIcon} title="No goals found" description="Create a goal to start tracking progress." />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {query.data.data.map((g) => (
                        <Card key={g.id} className="gap-0 py-0">
                            <CardContent className="grid gap-3 p-5">
                                <div className="flex items-start justify-between gap-2">
                                    <p className="min-w-0 font-semibold">{g.title}</p>
                                    <StatusBadge status={g.status} />
                                </div>
                                <div>
                                    <div className="mb-1 flex items-end justify-between">
                                        <span className="text-2xl font-semibold tabular">{pct(g.progress_percentage, 0)}</span>
                                        <span className="text-xs text-muted-foreground tabular" title={`Current = ${CURRENT_SOURCE}`}>{money(g.current_amount)} / {money(g.target_amount)} APE</span>
                                    </div>
                                    <Progress value={g.progress_percentage} className="h-2" aria-label={`${g.title} progress`} />
                                </div>
                                <div className="flex items-center justify-between text-xs">
                                    <span className={cn('text-muted-foreground', g.days_remaining !== null && g.days_remaining < 0 && ['not_started', 'in_progress'].includes(g.status) && 'font-medium text-destructive')}>
                                        {rangeLabel(g.start_date, g.target_date)}
                                        {g.days_remaining !== null && ['not_started', 'in_progress'].includes(g.status) && (g.days_remaining >= 0 ? ` · ${g.days_remaining} days left` : ` · ${-g.days_remaining} days overdue`)}
                                    </span>
                                    <span className="flex">
                                        <Button variant="ghost" size="icon" className="size-7" onClick={() => setDialog({ open: true, item: g })} aria-label="Edit goal"><Pencil className="size-3.5" /></Button>
                                        {user?.permissions.manage && <Button variant="ghost" size="icon" className="size-7 text-destructive" onClick={() => setRemoving(g)} aria-label="Delete goal"><Trash2 className="size-3.5" /></Button>}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            {meta2 && meta2.last_page > 1 && (
                <div className="flex items-center justify-center gap-2 text-sm">
                    <Button variant="outline" size="sm" disabled={meta2.current_page <= 1} onClick={() => table.set({ page: meta2.current_page - 1 })}>Previous</Button>
                    <span className="text-muted-foreground">Page {meta2.current_page} of {meta2.last_page}</span>
                    <Button variant="outline" size="sm" disabled={meta2.current_page >= meta2.last_page} onClick={() => table.set({ page: meta2.current_page + 1 })}>Next</Button>
                </div>
            )}

            <GoalDialog open={dialog.open} goal={dialog.item} onOpenChange={(o) => setDialog({ open: o, item: o ? dialog.item : null })} />
            <ConfirmDialog open={!!removing} onOpenChange={(o) => !o && setRemoving(null)} title="Delete goal?" description={removing?.title} onConfirm={() => removing && remove.mutate(removing)} loading={remove.isPending} />
        </div>
    );
}
