import { Fragment, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DataTable, type Column } from '@/components/data-table/data-table';
import { FilterSelect, TableToolbar } from '@/components/data-table/toolbar';
import { PageHeader } from '@/components/shared/misc';
import { DatePicker } from '@/components/shared/pickers';
import { api } from '@/lib/api';
import { dateTime, label } from '@/lib/format';
import { usePaginated, useTableState } from '@/hooks/use-data';
import type { AuditLog } from '@/lib/types';

function Changes({ log }: { log: AuditLog }) {
    const keys = [...new Set([...Object.keys(log.old_values ?? {}), ...Object.keys(log.new_values ?? {})])];
    if (!keys.length) return <p className="text-sm text-muted-foreground">No field changes recorded.</p>;
    const fmt = (v: unknown) => (v === null || v === undefined ? '∅' : typeof v === 'object' ? JSON.stringify(v) : String(v));
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-xs">
                <thead className="text-left text-muted-foreground">
                    <tr><th className="py-1 pr-4 font-medium">Field</th><th className="py-1 pr-4 font-medium">Old value</th><th className="py-1 font-medium">New value</th></tr>
                </thead>
                <tbody className="font-mono">
                    {keys.map((k) => (
                        <tr key={k} className="border-t align-top">
                            <td className="py-1.5 pr-4 font-sans font-medium">{k}</td>
                            <td className="max-w-[320px] py-1.5 pr-4 break-all text-red-700 dark:text-red-300">{log.old_values && k in log.old_values ? fmt(log.old_values[k]) : '—'}</td>
                            <td className="max-w-[320px] py-1.5 break-all text-emerald-700 dark:text-emerald-300">{log.new_values && k in log.new_values ? fmt(log.new_values[k]) : '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function AuditLogsPage() {
    const table = useTableState({ sort: 'created_at', direction: 'desc' });
    const query = usePaginated<AuditLog>('audit-logs', '/audit-logs', table);
    const [expanded, setExpanded] = useState<number | null>(null);
    const facets = useQuery({
        queryKey: ['audit-facets'],
        queryFn: async () => (await api.get<{ data: { modules: string[]; actions: string[] } }>('/audit-logs/facets')).data.data,
    });

    const columns: Column<AuditLog>[] = [
        {
            key: 'expand', header: <span className="sr-only">Details</span>, className: 'w-8', cell: (l) => (
                <Button variant="ghost" size="icon" className="size-7" onClick={() => setExpanded(expanded === l.id ? null : l.id)} aria-expanded={expanded === l.id} aria-label="Show changes">
                    {expanded === l.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                </Button>
            ),
        },
        { key: 'when', header: 'Timestamp', cell: (l) => <span className="whitespace-nowrap">{dateTime(l.created_at)}</span> },
        { key: 'user', header: 'User', cell: (l) => l.user?.name ?? <span className="text-muted-foreground">System</span> },
        { key: 'action', header: 'Action', cell: (l) => <span className="rounded bg-muted px-1.5 py-0.5 text-xs font-medium">{label(l.action)}</span> },
        { key: 'module', header: 'Module', cell: (l) => label(l.module) },
        { key: 'record', header: 'Record', hideBelow: 'sm', cell: (l) => (l.record_id ? `#${l.record_id}` : '—') },
        {
            key: 'desc', header: 'Details', hideBelow: 'md', cell: (l) => (
                <div className="max-w-md">
                    {l.description && <p className="text-sm">{l.description}</p>}
                    {expanded === l.id ? <div className="mt-2"><Changes log={l} /></div> : !l.description && <span className="text-xs text-muted-foreground">{Object.keys({ ...l.old_values, ...l.new_values }).slice(0, 4).join(', ') || '—'}</span>}
                </div>
            ),
        },
    ];

    return (
        <div className="grid gap-5">
            <PageHeader title="Audit Logs" description="Who changed what, and when. Old and new values are stored as JSON." />
            <TableToolbar table={table} placeholder="Search descriptions…">
                <FilterSelect table={table} name="module" placeholder="Module" options={(facets.data?.modules ?? []).map((m) => ({ value: m, label: label(m) }))} />
                <FilterSelect table={table} name="action" placeholder="Action" options={(facets.data?.actions ?? []).map((a) => ({ value: a, label: label(a) }))} />
                <div className="w-[170px]"><DatePicker value={table.params.date_from} onChange={(v) => table.set({ date_from: v })} clearable placeholder="From date" /></div>
                <div className="w-[170px]"><DatePicker value={table.params.date_to} onChange={(v) => table.set({ date_to: v })} clearable placeholder="To date" /></div>
            </TableToolbar>
            <Fragment>
                <DataTable columns={columns} query={query} table={table} rowKey={(l) => l.id} emptyTitle="No audit entries" />
            </Fragment>
            {expanded !== null && (
                <p className="text-xs text-muted-foreground md:hidden">Field changes are visible on wider screens.</p>
            )}
        </div>
    );
}
