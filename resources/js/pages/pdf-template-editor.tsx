import { useCallback, useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { PdfFieldEditor } from '@/components/pdf/pdf-field-editor';
import { api, errorMessage } from '@/lib/api';
import type { PdfField } from '@/lib/pdf-fields';
import { useAuth } from '@/hooks/use-auth';
import type { DocumentTemplate } from '@/lib/types';

/** Documents module: place placeholders on a PDF template. */
export default function PdfTemplateEditorPage() {
    const { id } = useParams();
    const qc = useQueryClient();
    const canManage = !!useAuth().user?.permissions.manage;

    const query = useQuery({
        queryKey: ['document-templates', 'detail', Number(id)],
        queryFn: async () => (await api.get<{ data: DocumentTemplate; placeholders: Record<string, string> }>(`/document-templates/${id}`)).data,
        staleTime: Infinity,
    });
    const template = query.data?.data;
    const labels = useMemo(() => query.data?.placeholders ?? {}, [query.data]);
    const palette = useMemo(() => Object.entries(labels).map(([key, label]) => ({ key, label })), [labels]);
    const isPdf = template?.extension === 'pdf';

    const loadSource = useCallback(
        async () => (await api.get<ArrayBuffer>(`/api/document-templates/${id}/download`, { baseURL: '', responseType: 'arraybuffer' })).data,
        [id],
    );

    const onSave = useCallback(async (fields: PdfField[]) => {
        const saved = (await api.put<{ data: DocumentTemplate }>(`/document-templates/${id}/pdf-fields`, { fields })).data.data;
        qc.invalidateQueries({ queryKey: ['document-templates'] });
        return saved.fillable ? 'The fields are filled with the client\'s details when this document is added to a client.' : 'No fields: the PDF will be copied as-is.';
    }, [id, qc]);

    if (!canManage) {
        return <Alert variant="destructive"><AlertDescription>Only admins and advisors can edit documents. <Link to="/documents" className="underline">Back to Documents</Link></AlertDescription></Alert>;
    }

    return (
        <PdfFieldEditor
            title={template?.name}
            backTo="/documents"
            backLabel="Back to Documents"
            loadSource={isPdf ? loadSource : null}
            error={query.isError ? errorMessage(query.error, 'Document not found.') : template && !isPdf ? 'Fields can only be placed on PDF documents. Word (.docx) templates use {{placeholders}} typed in the file.' : null}
            initialFields={template?.pdf_fields ?? []}
            palette={palette}
            previewValues={labels}
            onSave={onSave}
            hint="When this document is added to a client, each placeholder is filled with that client record's details (empty details are left blank). Text boxes are written as typed."
        />
    );
}
