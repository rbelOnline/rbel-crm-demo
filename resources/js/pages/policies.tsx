import { Link, useNavigate } from 'react-router-dom';
import { FileText, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { ExportButton, FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { PageHeader, StatusBadge } from '@/components/shared/misc';
import { DatePicker } from '@/components/shared/pickers';
import { useMeta, usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import { BulkDeleteBar } from '@/components/data-table/bulk-delete-bar';
import { RowDeleteButton } from '@/components/data-table/row-delete-button';
import { date, fullName, label, money, MONTHS } from '@/lib/format';
import type { Policy } from '@/lib/types';

export default function PoliciesPage() {
    const navigate = useNavigate();
    const meta = useMeta();
    const table = useTableState({ sort: 'issued_date', direction: 'asc' });
    const query = usePaginated<Policy>('policies', '/policies', table);
    const selection = useRowSelection(table);
    const canDelete = !!useAuth().user?.permissions.manage;

    const person = (p?: Policy['policy_owner']) =>
        p ? (
            <Link to={`/people/${p.id}`} onClick={(e) => e.stopPropagation()} className="whitespace-nowrap hover:underline">
                {fullName(p)}
            </Link>
        ) : (
            '—'
        );

    const columns: Column<Policy>[] = [
        {
            key: 'policy_number',
            header: 'Policy No.',
            sortKey: 'policy_number',
            cell: (p) => (
                <span className="inline-flex items-center gap-1.5 font-medium whitespace-nowrap">
                    {p.policy_number}
                    {p.coverage_document && <FileText className="size-3.5 text-muted-foreground" aria-label="Has coverage document" />}
                </span>
            ),
        },
        { key: 'owner', header: 'Policy Owner', sortKey: 'policy_owner', cell: (p) => person(p.policy_owner) },
        {
            key: 'insured',
            header: 'Policy Insured',
            sortKey: 'policy_insured',
            cell: (p) => (p.is_self_insured ? <span className="text-muted-foreground" title="Owner is the insured">Same as owner</span> : person(p.policy_insured)),
        },
        { key: 'product', header: 'Product', sortKey: 'product', hideBelow: 'lg', cell: (p) => <span className="whitespace-nowrap">{p.product?.name}</span> },
        { key: 'ape', header: 'APE', sortKey: 'ape', align: 'right', cell: (p) => money(p.ape) },
        { key: 'issued', header: 'Issued', sortKey: 'issued_date', hideBelow: 'md', cell: (p) => <span className="whitespace-nowrap">{date(p.issued_date)}</span> },
        { key: 'sum', header: 'Sum Assured', sortKey: 'sum_assured', align: 'right', hideBelow: 'xl', cell: (p) => money(p.sum_assured) },
        { key: 'status', header: 'Status', sortKey: 'status', cell: (p) => <StatusBadge status={p.status} /> },
        {
            key: 'delivery',
            header: 'Delivery',
            sortKey: 'policy_delivery_date',
            hideBelow: 'lg',
            cell: (p) => (p.policy_delivery_date ? <span className="whitespace-nowrap">{date(p.policy_delivery_date)}</span> : <StatusBadge status="pending" />),
        },
        { key: 'orphan', header: 'Orphan', sortKey: 'is_orphan', hideBelow: 'xl', cell: (p) => (p.is_orphan ? 'Yes' : <span className="text-muted-foreground">No</span>) },
        ...(canDelete
            ? [{
                  key: 'actions',
                  header: <span className="sr-only">Actions</span>,
                  className: 'w-10',
                  cell: (p: Policy) => (
                      <RowDeleteButton
                          url={`/policies/${p.id}`}
                          label="Delete client record"
                          title="Delete this client record?"
                          description={`Policy ${p.policy_number}, its beneficiaries and its coverage document will be permanently deleted. Beneficiaries who are also a lead, a client, or named on another policy are kept. The Policy Owner and Policy Insured people are kept.`}
                          success="Client record deleted."
                          invalidate={['policies', 'clients', 'client', 'dashboard', 'analytics', 'goals']}
                          onDeleted={() => selection.toggle(p.id, false)}
                      />
                  ),
              } satisfies Column<Policy>]
            : []),
    ];

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Clients"
                description="Every policy written, with its Policy Owner and Policy Insured."
                actions={
                    <>
                        <ExportButton table={table} url="/policies/export" fallbackName="clients.xlsx" />
                        <Button onClick={() => navigate('/clients/new')}>
                            <Plus className="size-4" /> New client
                        </Button>
                    </>
                }
            />

            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium text-muted-foreground">Issued date</span>
                <div className="w-[170px]"><DatePicker value={table.params.issued_from} onChange={(v) => table.set({ issued_from: v })} clearable placeholder="From" /></div>
                <span className="text-sm text-muted-foreground">to</span>
                <div className="w-[170px]"><DatePicker value={table.params.issued_to} onChange={(v) => table.set({ issued_to: v })} clearable placeholder="To" /></div>
            </div>

            <TableToolbar table={table} placeholder="Search policy no., owner, insured, product, email, mobile…">
                <FilterSelect table={table} name="status" placeholder="Status" options={(meta.data?.policy_statuses ?? []).map((s) => ({ value: s, label: label(s) }))} />
                <FilterSelect table={table} name="product_id" placeholder="Product" options={(meta.data?.products ?? []).map((p) => ({ value: String(p.id), label: p.name }))} />
                <FilterSelect table={table} name="mode_of_payment" placeholder="Mode" options={(meta.data?.payment_modes ?? []).map((m) => ({ value: m, label: label(m) }))} />
                <FilterSelect table={table} name="is_orphan" placeholder="Orphan" options={[{ value: '1', label: 'Orphan only' }, { value: '0', label: 'Not orphan' }]} />
                <FilterSelect table={table} name="delivery" placeholder="Delivery" options={[{ value: 'pending', label: 'Pending delivery' }, { value: 'delivered', label: 'Delivered' }]} />
                <FilterSelect table={table} name="birth_month" placeholder="Birth month" options={MONTHS.map((m, i) => ({ value: String(i + 1), label: m }))} />
            </TableToolbar>

            {canDelete && (
                <BulkDeleteBar
                    selection={selection}
                    url="/policies/bulk-delete"
                    noun={['client record', 'client records']}
                    invalidate={['policies', 'clients', 'client', 'dashboard', 'analytics', 'goals']}
                    warning="Each policy's beneficiaries and coverage document are deleted with it (beneficiaries who are also a lead, a client, or named on another policy are kept). The Policy Owner and Policy Insured people are kept."
                />
            )}

            <DataTable
                columns={columns}
                query={query}
                table={table}
                rowKey={(p) => p.id}
                selection={canDelete ? selection : undefined}
                onRowClick={(p) => navigate(`/clients/${p.id}`)}
                emptyTitle="No clients match"
                emptyDescription="Try adjusting your search or filters."
            />

        </div>
    );
}
