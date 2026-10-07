import { useState, type ReactNode } from 'react';
import { FileSpreadsheet, Loader2, Search, X } from 'lucide-react';
import { toast } from 'sonner';
import { downloadFile, errorMessage } from '@/lib/api';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { TableState } from '@/hooks/use-data';
import { cn } from '@/lib/utils';

/** Search box + filter slot + "Clear filters", all bound to URL table state. */
export function TableToolbar({ table, placeholder = 'Search…', children }: { table: TableState; placeholder?: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
            <div className="relative w-full lg:max-w-xs">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={table.params.search ?? ''}
                    onChange={(e) => table.set({ search: e.target.value })}
                    placeholder={placeholder}
                    className="pl-9"
                    aria-label="Search"
                />
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {children}
                {table.activeFilterCount > 0 && (
                    <Button variant="ghost" size="sm" onClick={table.clear} className="text-muted-foreground">
                        <X className="size-4" /> Clear filters
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * Downloads the list as an Excel file. Sends the table's current search,
 * filters and sort, so the file matches what is on screen (all pages).
 */
export function ExportButton({ table, url, fallbackName, extra = {} }: { table: TableState; url: string; fallbackName: string; extra?: Record<string, unknown> }) {
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);
        try {
            // Whole list, not just the visible page.
            const filters: Record<string, unknown> = { ...table.params };
            delete filters.page;
            delete filters.per_page;
            await downloadFile(url, { ...filters, sort: table.sort, direction: table.direction, ...extra }, fallbackName);
            toast.success('Excel report downloaded.');
        } catch (e) {
            toast.error(errorMessage(e, 'The report could not be generated.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Button variant="outline" onClick={run} disabled={busy}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : <FileSpreadsheet className="size-4" />} Download Excel
        </Button>
    );
}

const ALL = '__all';

/** A select that writes one URL filter; "All" clears it. */
export function FilterSelect({
    table,
    name,
    placeholder,
    options,
    className,
}: {
    table: TableState;
    name: string;
    placeholder: string;
    options: { value: string; label: string }[];
    className?: string;
}) {
    const value = table.params[name] ?? ALL;
    return (
        <Select value={value} onValueChange={(v) => table.set({ [name]: v === ALL ? null : v })}>
            <SelectTrigger size="sm" className={cn('min-w-[130px]', value !== ALL && 'border-primary/50 bg-accent/50', className)} aria-label={placeholder}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}: All</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
