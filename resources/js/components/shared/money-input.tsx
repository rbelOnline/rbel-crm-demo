import { useLayoutEffect, useRef, type ComponentProps } from 'react';
import { Input } from '@/components/ui/input';

/** Keep digits and one decimal point (max 2 decimals): "1,250,000.5x" -> "1250000.5". */
export function toRawAmount(text: string): string {
    const cleaned = text.replace(/[^\d.]/g, '');
    const dot = cleaned.indexOf('.');
    const int = (dot === -1 ? cleaned : cleaned.slice(0, dot)).replace(/^0+(?=\d)/, '');
    if (dot === -1) return int;
    return `${int}.${cleaned.slice(dot + 1).replace(/\./g, '').slice(0, 2)}`;
}

/** "1250000.5" -> "1,250,000.5" (keeps a trailing "." while typing). */
export function formatAmount(raw: string): string {
    if (!raw) return '';
    const [int, dec] = raw.split('.');
    const grouped = int.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return dec === undefined ? grouped : `${grouped || '0'}.${dec}`;
}

/** Number of significant characters (digits and ".") before position `pos`. */
const significantBefore = (text: string, pos: number) => text.slice(0, pos).replace(/[^\d.]/g, '').length;

/**
 * Amount field that shows thousands separators while typing. The form value
 * stays a plain numeric string ("1250000.50"), so validation and payloads
 * are unchanged; the caret is kept after the same digit when commas move.
 */
export function MoneyInput({ value, onChange, ...props }: Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & { value: string | null | undefined; onChange: (raw: string) => void }) {
    const ref = useRef<HTMLInputElement>(null);
    const caret = useRef<number | null>(null);
    const display = formatAmount(value ?? '');

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el || caret.current === null || document.activeElement !== el) return;
        // Place the caret after the same number of digits it followed before formatting.
        let seen = 0;
        let pos = 0;
        while (pos < display.length && seen < caret.current) {
            if (/[\d.]/.test(display[pos])) seen++;
            pos++;
        }
        el.setSelectionRange(pos, pos);
        caret.current = null;
    }, [display]);

    return (
        <Input
            {...props}
            ref={ref}
            type="text"
            inputMode="decimal"
            autoComplete="off"
            value={display}
            onChange={(e) => {
                caret.current = significantBefore(e.target.value, e.target.selectionStart ?? e.target.value.length);
                onChange(toRawAmount(e.target.value));
            }}
        />
    );
}
