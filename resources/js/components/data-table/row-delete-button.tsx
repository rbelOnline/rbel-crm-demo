import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/shared/pickers';
import { api, errorMessage } from '@/lib/api';

/**
 * Per-row trash button with its own confirm dialog. Clicks are contained so they
 * don't trigger the row's onRowClick (React events bubble through the dialog portal).
 */
export function RowDeleteButton({ url, label, title, description, success, invalidate, onDeleted }: {
    url: string;
    label: string;
    title: string;
    description: ReactNode;
    success: string;
    invalidate: string[];
    onDeleted?: () => void;
}) {
    const qc = useQueryClient();
    const [confirming, setConfirming] = useState(false);

    const remove = useMutation({
        mutationFn: () => api.delete(url),
        onSuccess: () => {
            toast.success(success);
            setConfirming(false);
            onDeleted?.();
            invalidate.forEach((key) => qc.invalidateQueries({ queryKey: [key] }));
        },
        onError: (e) => {
            setConfirming(false);
            toast.error(errorMessage(e));
        },
    });

    return (
        <span onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()} className="inline-flex">
            <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" aria-label={label} title={label} onClick={() => setConfirming(true)}>
                <Trash2 className="size-4" />
            </Button>
            <ConfirmDialog open={confirming} onOpenChange={setConfirming} title={title} description={description} onConfirm={() => remove.mutate()} loading={remove.isPending} />
        </span>
    );
}
