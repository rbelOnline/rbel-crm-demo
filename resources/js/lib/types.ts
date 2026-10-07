import type { PdfField } from '@/lib/pdf-fields';

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'advisor' | 'assistant';
    phone: string | null;
    job_title: string | null;
    license_number: string | null;
    bio: string | null;
    last_login_at: string | null;
    permissions: { manage: boolean; admin: boolean };
}

/** A client as it appears inside another record (policy owner / insured, appointment, …). Build the name with fullName(). */
export interface ClientSummary {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    age: number | null;
    gender: string | null;
    is_policy_owner: boolean;
}

export type PlanType = 'VUL' | 'TRAD';

export type FundSuitability = 'conservative' | 'moderate' | 'aggressive';

export interface FundType {
    id: number;
    name: string;
    suitability: FundSuitability | null;
    is_active: boolean;
    policies_count?: number;
    created_at?: string;
}

export interface Product {
    id: number;
    code?: string | null;
    name: string;
    plan_type: PlanType;
    category?: string;
    is_active?: boolean;
    policies_count?: number;
    created_at?: string;
}

/** A beneficiary designation, with the beneficiary's own details. */
export interface Beneficiary {
    id: number;
    policy_id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    birthdate: string | null;
    age: number | null;
    gender: string | null;
    email: string | null;
    mobile_number: string | null;
    policy?: Policy;
    relationship: string;
    beneficiary_type: 'primary' | 'contingent';
    designation: 'revocable' | 'irrevocable';
    allocation_percentage: number | null;
}

export interface Policy {
    id: number;
    policy_number: string;
    policy_owner_id: number;
    policy_insured_id: number;
    policy_owner?: ClientSummary;
    policy_insured?: ClientSummary;
    is_self_insured: boolean;
    product_id: number;
    product?: Product;
    ape: number;
    sum_assured: number;
    /** Optional, from the Fund Types module. */
    fund_type_ids?: number[];
    fund_types?: { id: number; name: string; suitability: FundSuitability | null }[];
    issued_date: string;
    mode_of_payment: string;
    status: string;
    status_changed_at: string | null;
    policy_delivery_date: string | null;
    is_orphan: boolean;
    remarks: string | null;
    coverage_document: { name: string; mime: string; size: number; uploaded_at: string } | null;
    beneficiaries_count?: number;
    beneficiaries?: Beneficiary[];
    created_at: string;
    updated_at: string;
}

/** A person on a policy. Only clients flagged is_policy_owner may own a policy; any client may be insured. */
export interface Client {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    occupation: string | null;
    birthdate: string | null;
    age: number | null;
    gender: string | null;
    email: string | null;
    mobile_number: string | null;
    address: string | null;
    is_policy_owner: boolean;
    owned_policies_count?: number;
    insured_policies_count?: number;
    in_force_owned_count?: number;
    total_ape_owned?: number;
    /** inactive = churned (terminated / lapsed / surrendered); completed = policies only matured. */
    client_status?: 'active' | 'inactive' | 'completed' | 'prospect';
    owned_policies?: Policy[];
    insured_policies?: Policy[];
    appointments?: Appointment[];
    created_at: string;
}

/** A prospect with no policy yet; converted into a Client when they buy. */
export interface Lead {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    occupation: string | null;
    birthdate: string | null;
    age: number | null;
    gender: string | null;
    email: string | null;
    mobile_number: string | null;
    notes: string | null;
    created_at: string;
}

export interface PolicyActivity {
    id: number;
    policy_number: string;
    issued_date: string;
    status: string;
    ape: string;
    role: 'owner' | 'insured' | 'owner_and_insured';
    previous_issued_date: string | null;
    next_issued_date: string | null;
    days_since_previous: number | null;
    cumulative_ape: string;
}

/** Calendar colour label of an appointment; each colour's name is in meta.appointment_labels. */
export type AppointmentLabel = 'green' | 'blue' | 'yellow' | 'red';

/** A personal schedule item on the Calendar (the signed-in user's own). */
export interface ScheduleItem {
    id: number;
    title: string;
    /** The day this occurrence is on (for a repeating item: one of its days). */
    date: string;
    /** First day of the series (same as `date` for a one-off item). */
    starts_on: string;
    start_time: string;
    end_time: string | null;
    repeat: 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly';
    /** Last day of a repeating item; null = no end. */
    repeat_until: string | null;
    label: AppointmentLabel | null;
    notes: string | null;
}

export interface Appointment {
    id: number;
    client_id: number;
    client?: ClientSummary;
    title: string;
    description: string | null;
    appointment_date: string;
    appointment_time: string;
    location: string | null;
    status: string;
    label: AppointmentLabel | null;
    notes: string | null;
}

export interface Goal {
    id: number;
    title: string;
    description: string | null;
    target_amount: number;
    current_amount: number;
    remaining_amount: number;
    /** "Date from": APE of policies issued from here to target_date counts toward the goal. */
    start_date: string;
    progress_percentage: number;
    target_date: string;
    days_remaining: number | null;
    status: string;
}

export interface Reminder {
    id: number;
    type: string;
    title: string;
    notes: string | null;
    due_date: string;
    is_overdue: boolean;
    completed_at: string | null;
    client_id: number | null;
    client?: ClientSummary | null;
    policy_id: number | null;
    policy_number?: string | null;
}

export interface EmailTemplate {
    id: number;
    name: string;
    subject: string;
    body: string;
    status: 'active' | 'draft' | 'archived';
    created_by?: string | null;
    logs_count?: number;
    updated_at: string;
}

export interface EmailLog {
    id: number;
    recipient: string;
    client?: { id: number; first_name: string; middle_name: string | null; last_name: string } | null;
    subject: string;
    template?: { id: number; name: string } | null;
    sent_by?: string | null;
    status: 'pending' | 'sent' | 'failed';
    error_message: string | null;
    sent_at: string | null;
    created_at: string;
}

export interface AuditLog {
    id: number;
    user?: { id: number; name: string } | null;
    action: string;
    module: string;
    record_id: number | null;
    description: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
}

export interface Meta {
    products: Product[];
    plan_types: PlanType[];
    fund_types: FundType[];
    fund_suitabilities: FundSuitability[];
    policy_statuses: string[];
    payment_modes: string[];
    relationships: string[];
    appointment_statuses: string[];
    appointment_labels: { key: AppointmentLabel; name: string }[];
    goal_statuses: string[];
    reminder_types: string[];
    template_statuses: string[];
    placeholders: Record<string, string>;
    years: number[];
    churn_definition: string;
    email_delivery_enabled: boolean;
}

export interface MonthlySales {
    year: number;
    months: { month: number; label: string; policy_count: number; total_ape: number; running_total: number; sales_rank: number | null; prev_month_ape: number | null }[];
    total_ape: number;
    policy_count: number;
    previous_year_ape: number;
    yoy_change_pct: number | null;
}

/** Distinct owners OR insureds per generation (by birth year), youngest first. */
export interface AgeDistribution {
    role: 'owner' | 'insured';
    year: number | null;
    ranges: { key: string; label: string; years: string; person_count: number; policy_count: number; total_ape: number; pct: number }[];
}

export type PersonRole = 'owner' | 'insured';

export interface DocumentTemplate {
    id: number;
    name: string;
    description: string | null;
    file_name: string;
    extension: string;
    size: number;
    /** Word .docx, or a PDF with positioned placeholders: filled with the client record's details. */
    fillable: boolean;
    placeholders: string[];
    /** PDFs only, and not in the list endpoint: placeholders positioned in the PDF editor. */
    pdf_fields?: PdfField[];
    is_active: boolean;
    client_documents_count?: number;
    uploaded_by?: string | null;
    updated_at: string;
}

export interface ClientDocument {
    id: number;
    policy_id: number;
    name: string;
    source: 'template' | 'upload';
    template?: { id: number; name: string } | null;
    file_name: string;
    extension: string;
    size: number;
    unfilled: string[];
    /** Word documents open in the in-app editor. */
    editable: boolean;
    /** Which in-app editor opens it. */
    editor: 'word' | 'pdf' | null;
    /** PDFs only: fields placed in the PDF editor. */
    pdf_fields?: PdfField[];
    edited_at: string | null;
    created_by?: string | null;
    created_at: string;
    updated_at: string;
}

export interface AutomationRunSummary {
    date: string;
    trigger: 'schedule' | 'manual';
    total: number;
    sent: number;
    failed: number;
    skipped: number;
    error: string | null;
    items: { name: string; detail: string; status: 'sent' | 'failed' | 'skipped'; reason: string | null }[];
}

export interface Automation {
    id: number;
    key: 'birthday_greeting' | 'premium_due' | 'policy_anniversary';
    label: string;
    enabled: boolean;
    email_template_id: number | null;
    template: { id: number; name: string; status: string } | null;
    send_time: string;
    sender_user_id: number | null;
    sender: { id: number; name: string; email: string } | null;
    last_run_at: string | null;
    last_run_summary: AutomationRunSummary | null;
}

export interface AutomationRecipient {
    client_id: number | null;
    name: string | null;
    email: string | null;
    policy_id: number | null;
    policy_number: string | null;
    detail: string;
    status: 'ready' | 'no_email' | 'already_sent';
}
