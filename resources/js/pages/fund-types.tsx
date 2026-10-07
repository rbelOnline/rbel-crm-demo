import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Loader2, Pencil, Plus, X } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { BulkDeleteBar } from '@/components/data-table/bulk-delete-bar';
import { RowDeleteButton } from '@/components/data-table/row-delete-button';
import { Field, PageHeader, StatusBadge } from '@/components/shared/misc';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, label, num } from '@/lib/format';
import { usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import type { FundSuitability, FundType } from '@/lib/types';
import { cn } from '@/lib/utils';

/** Investor risk profiles, lowest to highest risk (FundType::SUITABILITIES). */
export const SUITABILITIES: FundSuitability[] = ['conservative', 'moderate', 'aggressive'];

const SUITABILITY_STYLE: Record<FundSuitability, string> = {
    conservative: 'border-emerald-300 text-emerald-700 dark:border-emerald-500/40 dark:text-emerald-300',
    moderate: 'border-sky-300 text-sky-700 dark:border-sky-500/40 dark:text-sky-300',
    aggressive: 'border-red-300 text-red-700 dark:border-red-500/40 dark:text-red-300',
};

export function SuitabilityBadge({ value }: { value: FundSuitability | null | undefined }) {
    if (!value) return <span className="text-muted-foreground">—</span>;
    return <Badge variant="outline" className={cn('font-medium', SUITABILITY_STYLE[value])}>{label(value)}</Badge>;
}

/**
 * Pick any number of fund types (Clients form), shown by name only. Chosen funds show
 * as chips; the dropdown offers the active ones not yet added. A fund type that has
 * since been made inactive stays on the record until removed.
 */
export function FundTypesField({ value, onChange, options, id, invalid }: { value: number[]; onChange: (ids: number[]) => void; options: FundType[]; id?: string; invalid?: boolean }) {
    const chosen = value.map((v) => options.find((o) => o.id === v)).filter((o): o is FundType => !!o);
    const available = options.filter((o) => o.is_active && !value.includes(o.id));

    return (
        <div className="grid gap-2">
            {chosen.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {chosen.map((f) => (
                        <li key={f.id} className="flex items-center gap-2 rounded-lg border bg-background py-1 pr-1 pl-3 text-sm">
                            <span className="font-medium">{f.name}</span>
                            {!f.is_active && <span className="text-xs text-muted-foreground">inactive</span>}
                            <button type="button" className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" onClick={() => onChange(value.filter((v) => v !== f.id))} aria-label={`Remove ${f.name}`}>
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <Select value="" onValueChange={(v) => v && onChange([...value, Number(v)])} disabled={!available.length}>
                <SelectTrigger id={id} className="w-full sm:max-w-md" aria-invalid={invalid}>
                    <SelectValue placeholder={available.length ? (chosen.length ? 'Add another fund type…' : 'Add a fund type…') : chosen.length ? 'All fund types added' : 'No fund types yet — add them in Fund Types'} />
                </SelectTrigger>
                <SelectContent>
                    {available.map((f) => (
                        <SelectItem key={f.id} value={String(f.id)}>
                            {f.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function FundTypeDialog({ open, onOpenChange, fundType }: { open: boolean; onOpenChange: (o: boolean) => void; fundType: FundType | null }) {
    const qc = useQueryClient();
    const [v, setV] = useState<{ name: string; suitability: FundSuitability | ''; is_active: boolean }>({ name: '', suitability: '', is_active: true });
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setV(fundType ? { name: fundType.name, suitability: fundType.suitability ?? '', is_active: fundType.is_active !== false } : { name: '', suitability: '', is_active: true });
    }, [open, fundType]);

    const save = useMutation({
        mutationFn: () => {
            const local: Record<string, string> = {};
            if (!v.name.trim()) local.name = 'Fund type name is required.';
            if (!v.suitability) local.suitability = 'Choose a suitability.';
            if (Object.keys(local).length) {
                setErrors(local);
                throw new Error('local');
            }
            return fundType ? api.put(`/fund-types/${fundType.id}`, v) : api.post('/fund-types', v);
        },
        onSuccess: () => {
            toast.success(fundType ? 'Fund type updated.' : 'Fund type created.');
            // meta.fund_types feeds the client form's Fund Type dropdown.
            ['fund-types', 'meta', 'policies'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
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
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{fundType ? 'Edit fund type' : 'New fund type'}</DialogTitle>
                    <DialogDescription>Fund types are offered in the Fund Type dropdown when adding or editing a client record.</DialogDescription>
                </DialogHeader>
                <form id="fund-type-form" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="grid gap-4" noValidate>
                    <Field label="Name" htmlFor="fund_type_name" required error={errors.name}>
                        <Input id="fund_type_name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} aria-invalid={!!errors.name} maxLength={120} autoFocus />
                    </Field>
                    <Field label="Suitability" htmlFor="fund_type_suitability" required error={errors.suitability} hint="The investor risk profile this fund fits.">
                        <Select value={v.suitability || undefined} onValueChange={(s) => setV({ ...v, suitability: s as FundSuitability })}>
                            <SelectTrigger id="fund_type_suitability" className="w-full" aria-invalid={!!errors.suitability}><SelectValue placeholder="Select…" /></SelectTrigger>
                            <SelectContent>
                                {SUITABILITIES.map((s) => <SelectItem key={s} value={s}>{label(s)}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </Field>
                    <div className="flex items-center gap-2">
                        <Switch id="fund_type_active" checked={v.is_active} onCheckedChange={(is_active) => setV({ ...v, is_active })} />
                        <Label htmlFor="fund_type_active" className="text-sm font-normal">Active <span className="text-muted-foreground">(inactive fund types can't be chosen for new client records)</span></Label>
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="fund-type-form" disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} {fundType ? 'Save changes' : 'Create fund type'}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function FundTypesPage() {
    const canManage = !!useAuth().user?.permissions.manage;
    const table = useTableState({ sort: 'name', direction: 'asc' });
    const query = usePaginated<FundType>('fund-types', '/fund-types', table);
    const selection = useRowSelection(table);
    const [dialog, setDialog] = useState<{ open: boolean; item: FundType | null }>({ open: false, item: null });

    const columns: Column<FundType>[] = [
        { key: 'name', header: 'Name', sortKey: 'name', cell: (f) => <span className="font-medium">{f.name}</span> },
        { key: 'suitability', header: 'Suitability', sortKey: 'suitability', cell: (f) => <SuitabilityBadge value={f.suitability} /> },
        { key: 'status', header: 'Status', cell: (f) => <StatusBadge status={f.is_active === false ? 'inactive' : 'active'} /> },
        { key: 'policies', header: 'Client records', sortKey: 'policies', align: 'right', hideBelow: 'sm', cell: (f) => num(f.policies_count ?? 0) },
        { key: 'created_at', header: 'Added', sortKey: 'created_at', hideBelow: 'md', cell: (f) => <span className="whitespace-nowrap text-muted-foreground">{date(f.created_at)}</span> },
        ...(canManage
            ? [{
                  key: 'actions',
                  header: <span className="sr-only">Actions</span>,
                  className: 'w-20',
                  cell: (f: FundType) => (
                      <div className="flex items-center justify-end">
                          <Button variant="ghost" size="icon" className="size-8" aria-label="Edit fund type" title="Edit fund type" onClick={(e) => { e.stopPropagation(); setDialog({ open: true, item: f }); }}><Pencil className="size-4" /></Button>
                          <RowDeleteButton
                              url={`/fund-types/${f.id}`}
                              label="Delete fund type"
                              title="Delete this fund type?"
                              description={`"${f.name}" will be permanently deleted. Fund types used by client records can't be deleted; mark them inactive instead.`}
                              success="Fund type deleted."
                              invalidate={['fund-types', 'meta']}
                              onDeleted={() => selection.toggle(f.id, false)}
                          />
                      </div>
                  ),
              } satisfies Column<FundType>]
            : []),
    ];

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Fund Types"
                description="Options for the Fund Type dropdown on client records."
                actions={canManage && <Button onClick={() => setDialog({ open: true, item: null })}><Plus className="size-4" /> New fund type</Button>}
            />

            <TableToolbar table={table} placeholder="Search fund type…">
                <FilterSelect table={table} name="suitability" placeholder="Suitability" options={SUITABILITIES.map((s) => ({ value: s, label: label(s) }))} />
                <FilterSelect table={table} name="status" placeholder="Status" options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
            </TableToolbar>

            {canManage && <BulkDeleteBar selection={selection} url="/fund-types/bulk-delete" noun={['fund type', 'fund types']} invalidate={['fund-types', 'meta']} warning="Fund types used by client records are skipped; mark those inactive instead." />}

            <DataTable
                columns={columns}
                query={query}
                table={table}
                rowKey={(f) => f.id}
                selection={canManage ? selection : undefined}
                onRowClick={canManage ? (f) => setDialog({ open: true, item: f }) : undefined}
                emptyTitle="No fund types match"
                emptyDescription="Try adjusting your search or filter, or add a new fund type."
            />

            <FundTypeDialog open={dialog.open} fundType={dialog.item} onOpenChange={(o) => setDialog({ open: o, item: o ? dialog.item : null })} />
        </div>
    );
}
