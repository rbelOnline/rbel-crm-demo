import { z } from 'zod';
import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { toast } from 'sonner';
import { errorMessage, fieldErrors } from '@/lib/api';

/** Map Laravel 422 errors onto form fields; toast anything else. */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>) {
    const fields = fieldErrors(error);
    const keys = Object.keys(fields);
    keys.forEach((k) => setError(k as Path<T>, { type: 'server', message: fields[k] }));
    if (!keys.length) toast.error(errorMessage(error));
    else toast.error('Please fix the highlighted fields.');
}

/**
 * Scroll the first error in a form into view (inside a scrolling dialog too) and focus
 * its field. Runs after the next paint, once the error messages have rendered.
 */
export function scrollToFirstError(formId: string) {
    requestAnimationFrame(() => {
        const form = document.getElementById(formId);
        // Document order: the topmost invalid field or error message.
        const target = form?.querySelector<HTMLElement>('[aria-invalid="true"], [role="alert"]');
        if (!target) return;
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const field = target.matches('input, textarea, button, [tabindex]') ? target : null;
        field?.focus({ preventScroll: true });
    });
}

/** "" → null so optional fields clear on the server. */
export function nullify<T extends Record<string, unknown>>(values: T): T {
    return Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v])) as T;
}

export const MOBILE_RE = /^[0-9+\-\s()]{7,30}$/;

/** A person's name: letters (any language), spaces, periods, apostrophes and hyphens; no numbers. Same rule as the server (App\Rules\PersonName). */
export const NAME_RE = /^\p{L}[\p{L}\p{M}\s'.-]*$/u;

/** Why a name is invalid, or null. Empty is left to the "required" check. */
export function nameError(value: string): string | null {
    if (!value) return null;
    if (/\d/.test(value)) return 'Numbers are not allowed.';
    if (!NAME_RE.test(value)) return 'Letters, spaces, periods, apostrophes and hyphens only.';
    return null;
}

/** Zod schema for a first, middle or last name (optional here; require it with .min(1) or a refine). */
export const personName = (max = 80) =>
    z.string().trim().max(max, `At most ${max} characters.`).superRefine((v, ctx) => {
        const message = nameError(v);
        if (message) ctx.addIssue({ code: 'custom', message });
    });
