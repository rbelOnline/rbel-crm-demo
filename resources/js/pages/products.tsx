import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Loader2, Pencil, Plus } from 'lucide-react';
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
import { date, num } from '@/lib/format';
import { usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import type { PlanType, Product } from '@/lib/types';
import { cn } from '@/lib/utils';

const PLAN_TYPES: { value: PlanType; label: string; hint: string }[] = [
    { value: 'VUL', label: 'VUL', hint: 'Variable Universal Life (investment-linked)' },
    { value: 'TRAD', label: 'TRAD', hint: 'Traditional' },
];

export function PlanTypeBadge({ type }: { type: PlanType }) {
    return (
        <Badge variant="outline" className={cn('font-semibold', type === 'VUL' ? 'border-violet-300 text-violet-700 dark:border-violet-500/40 dark:text-violet-300' : 'border-sky-300 text-sky-700 dark:border-sky-500/40 dark:text-sky-300')}>
            {type}
        </Badge>
    );
}

function ProductDialog({ open, onOpenChange, product }: { open: boolean; onOpenChange: (o: boolean) => void; product: Product | null }) {
    const qc = useQueryClient();
    const [v, setV] = useState<{ name: string; plan_type: PlanType | ''; is_active: boolean }>({ name: '', plan_type: '', is_active: true });
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setV(product ? { name: product.name, plan_type: product.plan_type, is_active: product.is_active !== false } : { name: '', plan_type: '', is_active: true });
    }, [open, product]);

    const save = useMutation({
        mutationFn: () => {
            const local: Record<string, string> = {};
            if (!v.name.trim()) local.name = 'Plan name is required.';
            if (!v.plan_type) local.plan_type = 'Choose a plan type.';
            if (Object.keys(local).length) { setErrors(local); throw new Error('local'); }
            return product ? api.put(`/products/${product.id}`, v) : api.post('/products', v);
        },
        onSuccess: () => {
            toast.success(product ? 'Plan updated.' : 'Plan created.');
            // meta.products feeds the client form's plan dropdown.
            ['products', 'meta', 'policies'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
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
                    <DialogTitle>{product ? 'Edit plan' : 'New plan'}</DialogTitle>
                    <DialogDescription>Plans are offered when adding or editing a client record.</DialogDescription>
                </DialogHeader>
                <form id="product-form" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="grid gap-4" noValidate>
                    <Field label="Plan name" htmlFor="plan_name" required error={errors.name}>
                        <Input id="plan_name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} aria-invalid={!!errors.name} maxLength={120} autoFocus />
                    </Field>
                    <Field label="Plan type" htmlFor="plan_type" required error={errors.plan_type}>
                        <Select value={v.plan_type || undefined} onValueChange={(t) => setV({ ...v, plan_type: t as PlanType })}>
                            <SelectTrigger id="plan_type" className="w-full" aria-invalid={!!errors.plan_type}><SelectValue placeholder="Select…" /></SelectTrigger>
                            <SelectContent>
                                {PLAN_TYPES.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label} <span className="text-muted-foreground">· {t.hint}</span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <div className="flex items-center gap-2">
                        <Switch id="plan_active" checked={v.is_active} onCheckedChange={(is_active) => setV({ ...v, is_active })} />
                        <Label htmlFor="plan_active" className="text-sm font-normal">Active <span className="text-muted-foreground">(inactive plans can't be chosen for new clients)</span></Label>
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="product-form" disabled={save.isPending}>{save.isPending && <Loader2 className="size-4 animate-spin" />} {product ? 'Save changes' : 'Create plan'}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function ProductsPage() {
    const canManage = !!useAuth().user?.permissions.manage;
    const table = useTableState({ sort: 'name', direction: 'asc' });
    const query = usePaginated<Product>('products', '/products', table);
    const selection = useRowSelection(table);
    const [dialog, setDialog] = useState<{ open: boolean; item: Product | null }>({ open: false, item: null });

    const columns: Column<Product>[] = [
        { key: 'name', header: 'Plan name', sortKey: 'name', cell: (p) => <span className="font-medium">{p.name}</span> },
        { key: 'plan_type', header: 'Plan type', sortKey: 'plan_type', cell: (p) => <PlanTypeBadge type={p.plan_type} /> },
        { key: 'status', header: 'Status', cell: (p) => <StatusBadge status={p.is_active === false ? 'inactive' : 'active'} /> },
        { key: 'policies', header: 'Client records', sortKey: 'policies', align: 'right', hideBelow: 'sm', cell: (p) => num(p.policies_count ?? 0) },
        { key: 'created_at', header: 'Added', sortKey: 'created_at', hideBelow: 'md', cell: (p) => <span className="whitespace-nowrap text-muted-foreground">{date(p.created_at)}</span> },
        ...(canManage
            ? [{
                  key: 'actions',
                  header: <span className="sr-only">Actions</span>,
                  className: 'w-20',
                  cell: (p: Product) => (
                      <div className="flex items-center justify-end">
                          <Button variant="ghost" size="icon" className="size-8" aria-label="Edit plan" title="Edit plan" onClick={(e) => { e.stopPropagation(); setDialog({ open: true, item: p }); }}><Pencil className="size-4" /></Button>
                          <RowDeleteButton
                              url={`/products/${p.id}`}
                              label="Delete plan"
                              title="Delete this plan?"
                              description={`"${p.name}" will be permanently deleted. Plans used by client records can't be deleted; mark them inactive instead.`}
                              success="Plan deleted."
                              invalidate={['products', 'meta']}
                              onDeleted={() => selection.toggle(p.id, false)}
                          />
                      </div>
                  ),
              } satisfies Column<Product>]
            : []),
    ];

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Products"
                description="Insurance plans offered to clients, by plan type (VUL or TRAD)."
                actions={canManage && <Button onClick={() => setDialog({ open: true, item: null })}><Plus className="size-4" /> New plan</Button>}
            />

            <TableToolbar table={table} placeholder="Search plan name…">
                <FilterSelect table={table} name="plan_type" placeholder="Plan type" options={PLAN_TYPES.map((t) => ({ value: t.value, label: `${t.label} · ${t.hint}` }))} />
                <FilterSelect table={table} name="status" placeholder="Status" options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
            </TableToolbar>

            {canManage && <BulkDeleteBar selection={selection} url="/products/bulk-delete" noun={['plan', 'plans']} invalidate={['products', 'meta']} warning="Plans used by client records are skipped; mark those inactive instead." />}

            <DataTable
                columns={columns}
                query={query}
                table={table}
                rowKey={(p) => p.id}
                selection={canManage ? selection : undefined}
                onRowClick={canManage ? (p) => setDialog({ open: true, item: p }) : undefined}
                emptyTitle="No plans match"
                emptyDescription="Try adjusting your search or filters, or add a new plan."
            />

            <ProductDialog open={dialog.open} product={dialog.item} onOpenChange={(o) => setDialog({ open: o, item: o ? dialog.item : null })} />
        </div>
    );
}
