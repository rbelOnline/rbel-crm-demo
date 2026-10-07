import type { ReactNode } from 'react';
import { AlertCircle, ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Inbox } from 'lucide-react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/lib/types';
import { Checkbox } from '@/components/ui/checkbox';
import type { RowSelection, TableState } from '@/hooks/use-data';

export interface Column<T> {
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    /** Server-side sort key; omit for unsortable columns. */
    sortKey?: string;
    className?: string;
    /** Hide below this breakpoint to keep tables usable on small screens. */
    hideBelow?: 'sm' | 'md' | 'lg' | 'xl';
    align?: 'left' | 'right' | 'center';
}

const hideClass = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell', xl: 'hidden xl:table-cell' };

interface Props<T> {
    columns: Column<T>[];
    query: { data?: Paginated<T>; isLoading: boolean; isError: boolean; isFetching?: boolean; refetch: () => void };
    table?: TableState;
    rowKey: (row: T) => string | number;
    onRowClick?: (row: T) => void;
    emptyTitle?: string;
    emptyDescription?: ReactNode;
    /** Adds a checkbox column for mass actions. */
    selection?: RowSelection;
}

/** Server-driven table: sorting, pagination, loading, empty and error states. 10 rows per page (server default). */
export function DataTable<T>({ columns: baseColumns, query, table, rowKey, onRowClick, emptyTitle = 'No records found', emptyDescription, selection }: Props<T>) {
    const rows = query.data?.data ?? [];
    const meta = query.data?.meta;

    const pageIds = rows.map(rowKey);
    const pageSelected = selection ? pageIds.filter((id) => selection.isSelected(id)).length : 0;
    const columns: Column<T>[] = selection
        ? [
              {
                  key: '__select',
                  className: 'w-10',
                  header: (
                      <Checkbox
                          aria-label="Select all rows on this page"
                          checked={pageSelected === 0 ? false : pageSelected === pageIds.length ? true : 'indeterminate'}
                          onCheckedChange={(on) => selection.setMany(pageIds, on === true)}
                          disabled={pageIds.length === 0}
                      />
                  ),
                  cell: (row) => (
                      // Stop the click so ticking a box does not open the row.
                      <span onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()} className="flex">
                          <Checkbox aria-label="Select row" checked={selection.isSelected(rowKey(row))} onCheckedChange={(on) => selection.toggle(rowKey(row), on === true)} />
                      </span>
                  ),
              },
              ...baseColumns,
          ]
        : baseColumns;

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <div className={cn('relative overflow-x-auto', query.isFetching && !query.isLoading && 'opacity-70 transition-opacity')}>
                <Table>
                    <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                            {columns.map((col) => (
                                <TableHead
                                    key={col.key}
                                    className={cn('h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground', col.hideBelow && hideClass[col.hideBelow], col.align === 'right' && 'text-right', col.className)}
                                    aria-sort={table && col.sortKey && table.sort === col.sortKey ? (table.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                >
                                    {col.sortKey && table ? (
                                        <button
                                            type="button"
                                            onClick={() => table.toggleSort(col.sortKey!)}
                                            className={cn('-mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none', col.align === 'right' && 'flex-row-reverse')}
                                        >
                                            {col.header}
                                            {table.sort === col.sortKey ? (
                                                table.direction === 'asc' ? <ArrowUp className="size-3.5" /> : <ArrowDown className="size-3.5" />
                                            ) : (
                                                <ArrowUpDown className="size-3.5 opacity-40" />
                                            )}
                                        </button>
                                    ) : (
                                        col.header
                                    )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {query.isLoading &&
                            Array.from({ length: 6 }).map((_, i) => (
                                <TableRow key={`s${i}`}>
                                    {columns.map((col) => (
                                        <TableCell key={col.key} className={cn(col.hideBelow && hideClass[col.hideBelow])}>
                                            <Skeleton className="h-4 w-full max-w-[140px]" />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}

                        {query.isError && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={columns.length} className="py-14 text-center">
                                    <AlertCircle className="mx-auto mb-2 size-8 text-destructive" />
                                    <p className="font-medium">Could not load data</p>
                                    <Button variant="outline" size="sm" className="mt-3" onClick={() => query.refetch()}>
                                        Try again
                                    </Button>
                                </TableCell>
                            </TableRow>
                        )}

                        {!query.isLoading && !query.isError && rows.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={columns.length} className="py-14 text-center">
                                    <Inbox className="mx-auto mb-2 size-8 text-muted-foreground/60" />
                                    <p className="font-medium">{emptyTitle}</p>
                                    {emptyDescription && <div className="mt-1 text-sm text-muted-foreground">{emptyDescription}</div>}
                                </TableCell>
                            </TableRow>
                        )}

                        {!query.isLoading &&
                            rows.map((row) => (
                                <TableRow
                                    key={rowKey(row)}
                                    data-state={selection?.isSelected(rowKey(row)) ? 'selected' : undefined}
                                    className={cn(onRowClick && 'cursor-pointer')}
                                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                                    tabIndex={onRowClick ? 0 : undefined}
                                    onKeyDown={onRowClick ? (e) => e.key === 'Enter' && e.target === e.currentTarget && onRowClick(row) : undefined}
                                >
                                    {columns.map((col) => (
                                        <TableCell
                                            key={col.key}
                                            className={cn('py-3', col.hideBelow && hideClass[col.hideBelow], col.align === 'right' && 'text-right tabular', col.className)}
                                        >
                                            {col.cell(row)}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                    </TableBody>
                </Table>
            </div>

            {meta && table && meta.total > 0 && (
                <div className="flex flex-col gap-3 border-t px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-muted-foreground">
                        Showing <span className="font-medium text-foreground">{meta.from}</span>–<span className="font-medium text-foreground">{meta.to}</span> of{' '}
                        <span className="font-medium text-foreground">{meta.total.toLocaleString()}</span>
                    </p>
                    <div className="flex items-center gap-2">
                        <span className="px-1 text-muted-foreground">
                            {meta.current_page} / {meta.last_page}
                        </span>
                        <Button variant="outline" size="icon" className="size-8" disabled={meta.current_page <= 1} onClick={() => table.set({ page: 1 })} aria-label="First page">
                            <ChevronsLeft className="size-4" />
                        </Button>
                        <Button variant="outline" size="icon" className="size-8" disabled={meta.current_page <= 1} onClick={() => table.set({ page: meta.current_page - 1 })} aria-label="Previous page">
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Button variant="outline" size="icon" className="size-8" disabled={meta.current_page >= meta.last_page} onClick={() => table.set({ page: meta.current_page + 1 })} aria-label="Next page">
                            <ChevronRight className="size-4" />
                        </Button>
                        <Button variant="outline" size="icon" className="size-8" disabled={meta.current_page >= meta.last_page} onClick={() => table.set({ page: meta.last_page })} aria-label="Last page">
                            <ChevronsRight className="size-4" />
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
