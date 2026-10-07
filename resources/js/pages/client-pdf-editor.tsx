import { useCallback, useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { PdfFieldEditor } from '@/components/pdf/pdf-field-editor';
import { api, errorMessage } from '@/lib/api';
import { stampPdf, type PdfField } from '@/lib/pdf-fields';
import type { ClientDocument } from '@/lib/types';

type Field = { key: string; label: string; value: string };

/**
 * Clients module: fill a PDF on a client record by placing this record's details
 * (and free text) on it. Saving stamps them onto the unfilled original and replaces the file.
 */
export default function ClientPdfEditorPage() {
    const { policyId, documentId } = useParams();
    const qc = useQueryClient();

    const docs = useQuery({
        queryKey: ['client-documents', Number(policyId)],
        queryFn: async () => (await api.get<{ data: ClientDocument[] }>(`/policies/${policyId}/documents`)).data.data,
    });
    const doc = docs.data?.find((d) => String(d.id) === documentId);

    const fields = useQuery({
        queryKey: ['client-document-fields', Number(policyId)],
        queryFn: async () => (await api.get<{ data: Field[] }>(`/policies/${policyId}/documents/fields`)).data.data,
    });
    const values = useMemo(() => Object.fromEntries((fields.data ?? []).map((f) => [f.key, f.value])), [fields.data]);

    const loadSource = useCallback(
        async () => (await api.get<ArrayBuffer>(`/api/policies/${policyId}/documents/${documentId}/pdf-source`, { baseURL: '', responseType: 'arraybuffer' })).data,
        [policyId, documentId],
    );

    const onSave = useCallback(async (placed: PdfField[], source: ArrayBuffer) => {
        let bytes: Uint8Array;
        try {
            bytes = await stampPdf(source, placed, values);
        } catch {
            throw new Error('The PDF could not be filled. It may be damaged or password-protected.');
        }
        const fd = new FormData();
        fd.append('_method', 'PUT');
        fd.append('fields', JSON.stringify(placed));
        fd.append('file', new Blob([bytes as BlobPart], { type: 'application/pdf' }), 'filled.pdf');
        const saved = (await api.post<{ data: ClientDocument }>(`/policies/${policyId}/documents/${documentId}/pdf`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data;
        qc.invalidateQueries({ queryKey: ['client-documents', Number(policyId)] });
        return saved.unfilled.length
            ? `Left blank (no value on record): ${saved.unfilled.map((u) => fields.data?.find((f) => f.key === u)?.label ?? u).join(', ')}.`
            : 'The PDF was updated.';
    }, [values, policyId, documentId, qc, fields.data]);

    const error = docs.isError || fields.isError
        ? errorMessage(docs.error ?? fields.error, 'The document could not be loaded.')
        : docs.isSuccess && !doc ? 'Document not found.'
        : doc && doc.editor !== 'pdf' ? 'Only PDF documents open in the PDF editor.'
        : null;

    return (
        <PdfFieldEditor
            title={doc?.name}
            backTo={`/clients/${policyId}`}
            backLabel="Back to client"
            loadSource={doc?.editor === 'pdf' && fields.isSuccess ? loadSource : null}
            error={error}
            initialFields={doc?.pdf_fields ?? []}
            palette={fields.data ?? []}
            values={values}
            previewValues={values}
            onSave={onSave}
            hint="Each field shows this client record's details; saving writes them onto the PDF. Fields can be moved or removed later: the original page is kept."
        />
    );
}
