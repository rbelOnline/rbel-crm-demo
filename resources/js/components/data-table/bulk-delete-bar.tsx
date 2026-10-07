import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trash2, X } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/shared/pickers';
import { api, errorMessage } from '@/lib/api';
import type { RowSelection } from '@/hooks/use-data';

/** Matches BulkDeleteRequest::MAX on the server. */
const MAX = 100;

type Result = { deleted: number; skipped: { id: number; name: string; reason: string }[] };

/**
 * Appears when rows are checked: count, clear, and "Delete selected".
 * The server applies the same rules as single delete and reports skipped rows.
 */
export function BulkDeleteBar({ selection, url, noun, invalidate, warning }: { selection: RowSelection; url: string; noun: [singular: string, plural: string]; invalidate: string[]; warning?: string }) {
    const qc = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const word = (n: number) => (n === 1 ? noun[0] : noun[1]);

    const run = useMutation({
        mutationFn: async () => (await api.post<Result>(url, { ids: [...selection.ids] })).data,
        onSuccess: ({ deleted, skipped }) => {
            if (deleted) toast.success(`Deleted ${deleted} ${word(deleted)}.`);
            if (skipped.length) {
                toast.warning(`${skipped.length} ${word(skipped.length)} not deleted`, {
                    description: (
                        <ul className="mt-1 grid gap-0.5">
                            {skipped.slice(0, 4).map((s) => (
                                <li key={s.id}>
                                    <span className="font-medium">{s.name}</span>: {s.reason}
                                </li>
                            ))}
                            {skipped.length > 4 && <li>…and {skipped.length - 4} more</li>}
                        </ul>
                    ),
                    duration: 10_000,
                });
            }
            selection.clear();
            setConfirming(false);
            invalidate.forEach((key) => qc.invalidateQueries({ queryKey: [key] }));
        },
        onError: (e) => {
            toast.error(errorMessage(e, 'The selected records could not be deleted.'));
            setConfirming(false);
        },
    });

    if (selection.count === 0) return null;
    const tooMany = selection.count > MAX;

    return (
        <>
            <div className="flex flex-wrap items-center gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-2.5 text-sm" role="status">
                <span className="font-medium">
                    {selection.count} {word(selection.count)} selected
                </span>
                <Button variant="ghost" size="sm" onClick={selection.clear} className="text-muted-foreground">
                    <X className="size-4" /> Clear
                </Button>
                {tooMany && <span className="text-xs text-destructive">Select at most {MAX} at a time.</span>}
                <Button variant="destructive" size="sm" className="ml-auto" onClick={() => setConfirming(true)} disabled={tooMany}>
                    <Trash2 className="size-4" /> Delete selected
                </Button>
            </div>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Delete ${selection.count} ${word(selection.count)}?`}
                description={`This cannot be undone.${warning ? ` ${warning}` : ''}`}
                confirmLabel={`Delete ${selection.count}`}
                onConfirm={() => run.mutate()}
                loading={run.isPending}
            />
        </>
    );
}
