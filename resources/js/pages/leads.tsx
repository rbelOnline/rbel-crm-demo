import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, UserCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { ExportButton, FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { PageHeader } from '@/components/shared/misc';
import { ConvertLeadDialog, LeadFormDialog } from '@/pages/client-form';
import { usePaginated, useRowSelection, useTableState } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import { BulkDeleteBar } from '@/components/data-table/bulk-delete-bar';
import { RowDeleteButton } from '@/components/data-table/row-delete-button';
import { date, fullName, label, MONTHS } from '@/lib/format';
import type { Lead } from '@/lib/types';

/**
 * Leads: prospects with no policy yet, kept apart from clients.
 * Converting a lead copies them into Clients and removes the lead.
 */
export default function LeadsPage() {
    const navigate = useNavigate();
    const table = useTableState({ sort: 'created_at', direction: 'desc' });
    const query = usePaginated<Lead>('leads', '/leads', table);
    const selection = useRowSelection(table);
    const canDelete = !!useAuth().user?.permissions.manage;
    const [editing, setEditing] = useState<Lead | null>(null);
    const [creating, setCreating] = useState(false);
    const [converting, setConverting] = useState<Lead | null>(null);

    const columns: Column<Lead>[] = [
        {
            key: 'name',
            header: 'Name',
            sortKey: 'name',
            cell: (l) => (
                <div className="min-w-[160px]">
                    <p className="font-medium">{fullName(l)}</p>
                    <p className="text-xs text-muted-foreground sm:hidden">{l.email ?? l.mobile_number}</p>
                </div>
            ),
        },
        { key: 'email', header: 'Email', sortKey: 'email', hideBelow: 'sm', cell: (l) => <span className="text-muted-foreground">{l.email ?? '—'}</span> },
        { key: 'mobile', header: 'Mobile', hideBelow: 'md', cell: (l) => <span className="whitespace-nowrap text-muted-foreground">{l.mobile_number ?? '—'}</span> },
        { key: 'birthdate', header: 'Birthdate', sortKey: 'birthdate', hideBelow: 'xl', cell: (l) => <span className="whitespace-nowrap">{date(l.birthdate)} {l.age !== null && <span className="text-muted-foreground">({l.age})</span>}</span> },
        { key: 'occupation', header: 'Occupation', hideBelow: 'lg', cell: (l) => <span className="text-muted-foreground">{l.occupation ?? '—'}</span> },
        { key: 'notes', header: 'Notes', hideBelow: 'xl', cell: (l) => <span className="line-clamp-1 max-w-[240px] text-muted-foreground">{l.notes ?? '—'}</span> },
        { key: 'created_at', header: 'Added', sortKey: 'created_at', hideBelow: 'md', cell: (l) => <span className="whitespace-nowrap">{date(l.created_at)}</span> },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-px',
            cell: (l: Lead) => (
                <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                    <Button variant="outline" size="sm" onClick={() => setConverting(l)}>
                        <UserCheck className="size-4" /> <span className="hidden lg:inline">Convert</span>
                    </Button>
                    {canDelete && (
                        <RowDeleteButton
                            url={`/leads/${l.id}`}
                            label="Delete lead"
                            title="Delete this lead?"
                            description={`${fullName(l)} will be permanently removed.`}
                            success="Lead deleted."
                            invalidate={['leads', 'dashboard']}
                            onDeleted={() => selection.toggle(l.id, false)}
                        />
                    )}
                </div>
            ),
        },
    ];

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Leads"
                description="Prospects without a policy yet. Convert a lead to a client when they buy."
                actions={
                    <>
                        <ExportButton table={table} url="/leads/export" fallbackName="leads.xlsx" />
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" /> New lead
                        </Button>
                    </>
                }
            />

            <TableToolbar table={table} placeholder="Search name, email or mobile…">
                <FilterSelect table={table} name="gender" placeholder="Gender" options={['male', 'female', 'other'].map((s) => ({ value: s, label: label(s) }))} />
                <FilterSelect table={table} name="birth_month" placeholder="Birth month" options={MONTHS.map((m, i) => ({ value: String(i + 1), label: m }))} />
            </TableToolbar>

            {canDelete && <BulkDeleteBar selection={selection} url="/leads/bulk-delete" noun={['lead', 'leads']} invalidate={['leads', 'dashboard']} />}

            <DataTable
                columns={columns}
                query={query}
                table={table}
                rowKey={(l) => l.id}
                selection={canDelete ? selection : undefined}
                onRowClick={(l) => setEditing(l)}
                emptyTitle="No leads match"
                emptyDescription="Try adjusting your search or filters, or add a new lead."
            />

            <LeadFormDialog open={creating || !!editing} onOpenChange={(o) => { if (!o) { setCreating(false); setEditing(null); } }} lead={editing} />
            <ConvertLeadDialog open={!!converting} onOpenChange={(o) => !o && setConverting(null)} lead={converting} onConverted={(c) => navigate(`/people/${c.id}`)} />
        </div>
    );
}
