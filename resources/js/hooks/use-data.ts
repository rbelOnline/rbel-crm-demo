import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { api, cleanParams } from '@/lib/api';
import type { Meta, Paginated } from '@/lib/types';

export function useDebounce<T>(value: T, delay = 350): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const t = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(t);
    }, [value, delay]);
    return debounced;
}

/** Reference data for dropdowns; cached for the session. */
export function useMeta() {
    return useQuery({
        queryKey: ['meta'],
        queryFn: async () => (await api.get<{ data: Meta }>('/meta')).data.data,
        staleTime: 5 * 60_000,
    });
}

export interface TableState {
    params: Record<string, string>;
    set: (patch: Record<string, string | number | null | undefined>) => void;
    clear: () => void;
    sort: string;
    direction: 'asc' | 'desc';
    page: number;
    toggleSort: (key: string) => void;
    activeFilterCount: number;
}

/**
 * Table state (search, filters, sort, page) lives in the URL so views are
 * shareable and survive refresh. Any change other than `page` resets to page 1.
 */
export function useTableState(defaults: { sort: string; direction: 'asc' | 'desc' }): TableState {
    const [searchParams, setSearchParams] = useSearchParams();
    const params = useMemo(() => Object.fromEntries(searchParams.entries()), [searchParams]);

    const set: TableState['set'] = (patch) => {
        setSearchParams(
            (prev) => {
                const next = new URLSearchParams(prev);
                for (const [k, v] of Object.entries(patch)) {
                    if (v === null || v === undefined || v === '') next.delete(k);
                    else next.set(k, String(v));
                }
                if (!('page' in patch)) next.delete('page');
                return next;
            },
            { replace: true },
        );
    };

    const sort = params.sort ?? defaults.sort;
    const direction = (params.direction as 'asc' | 'desc') ?? defaults.direction;

    return {
        params,
        set,
        clear: () => setSearchParams(params.tab ? { tab: params.tab } : {}, { replace: true }),
        sort,
        direction,
        page: Number(params.page ?? 1),
        toggleSort: (key) => set({ sort: key, direction: sort === key && direction === 'asc' ? 'desc' : 'asc' }),
        activeFilterCount: Object.keys(params).filter((k) => !['page', 'sort', 'direction', 'per_page', 'tab'].includes(k)).length,
    };
}

export type RowId = number | string;

export interface RowSelection {
    ids: Set<RowId>;
    count: number;
    isSelected: (id: RowId) => boolean;
    toggle: (id: RowId, on?: boolean) => void;
    setMany: (ids: RowId[], on: boolean) => void;
    clear: () => void;
}

/**
 * Checked rows for mass actions. Survives paging and sorting, but is cleared
 * when the search or filters change so hidden rows are never acted on.
 */
export function useRowSelection(table: TableState): RowSelection {
    const [ids, setIds] = useState<Set<RowId>>(() => new Set());
    const filterKey = JSON.stringify(Object.entries(table.params).filter(([k]) => !['page', 'sort', 'direction', 'per_page', 'tab'].includes(k)));

    useEffect(() => setIds(new Set()), [filterKey]);

    return {
        ids,
        count: ids.size,
        isSelected: (id) => ids.has(id),
        toggle: (id, on) =>
            setIds((prev) => {
                const next = new Set(prev);
                if (on ?? !next.has(id)) next.add(id);
                else next.delete(id);
                return next;
            }),
        setMany: (many, on) =>
            setIds((prev) => {
                const next = new Set(prev);
                many.forEach((id) => (on ? next.add(id) : next.delete(id)));
                return next;
            }),
        clear: () => setIds(new Set()),
    };
}

/** Server-side paginated list bound to a TableState. */
export function usePaginated<T>(key: string, url: string, table: TableState, extra: Record<string, unknown> = {}) {
    // Tables always show the server's default page size (10); ignore any per_page in the URL.
    const { search, ...rest } = table.params;
    delete rest.per_page;
    const debouncedSearch = useDebounce(search ?? '');

    const query = cleanParams({ ...rest, search: debouncedSearch, sort: table.sort, direction: table.direction, ...extra });

    return useQuery({
        queryKey: [key, query],
        queryFn: async () => (await api.get<Paginated<T>>(url, { params: query })).data,
        placeholderData: keepPreviousData,
    });
}
