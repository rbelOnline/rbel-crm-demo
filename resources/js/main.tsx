import { StrictMode, lazy, Suspense, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { AppLayout } from '@/components/layout/app-layout';
import { AuthProvider, useAuth } from '@/hooks/use-auth';
import { ThemeProvider } from '@/hooks/use-theme';
import LoginPage from '@/pages/login';

const DashboardPage = lazy(() => import('@/pages/dashboard'));
const LeadsPage = lazy(() => import('@/pages/leads'));
const PersonDetailPage = lazy(() => import('@/pages/person-detail'));
const PoliciesPage = lazy(() => import('@/pages/policies'));
const PolicyDetailPage = lazy(() => import('@/pages/policy-detail'));
const PolicyFormPage = lazy(() => import('@/pages/policy-form-page'));
const AppointmentsPage = lazy(() => import('@/pages/appointments'));
const ProductsPage = lazy(() => import('@/pages/products'));
const CalendarPage = lazy(() => import('@/pages/calendar'));
const FundTypesPage = lazy(() => import('@/pages/fund-types'));
const DocumentsPage = lazy(() => import('@/pages/documents'));
const ClientDocumentEditorPage = lazy(() => import('@/pages/client-document-editor'));
const PdfTemplateEditorPage = lazy(() => import('@/pages/pdf-template-editor'));
const ClientPdfEditorPage = lazy(() => import('@/pages/client-pdf-editor'));
const RemindersPage = lazy(() => import('@/pages/reminders'));
const GoalsPage = lazy(() => import('@/pages/goals'));
const EmailTemplatesPage = lazy(() => import('@/pages/email-templates'));
const AnalyticsPage = lazy(() => import('@/pages/analytics'));
const AuditLogsPage = lazy(() => import('@/pages/audit-logs'));
const AutomationsPage = lazy(() => import('@/pages/automations'));
const ProfilePage = lazy(() => import('@/pages/profile'));
const NotFoundPage = lazy(() => import('@/pages/not-found'));


const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            retry: (count, error: unknown) => {
                const status = (error as { response?: { status?: number } })?.response?.status;
                return status !== undefined && status >= 400 && status < 500 ? false : count < 2;
            },
        },
    },
});

function Spinner() {
    return (
        <div className="grid min-h-[50vh] place-items-center" role="status" aria-label="Loading">
            <Loader2 className="size-6 animate-spin text-primary" />
        </div>
    );
}

function RequireAuth({ children, admin }: { children: ReactNode; admin?: boolean }) {
    const { user, loading } = useAuth();
    const location = useLocation();
    if (loading) return <Spinner />;
    if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
    if (admin && !user.permissions.admin) return <Navigate to="/" replace />;
    return <>{children}</>;
}

/** Policies were renamed to Clients; keep old /policies/:id links working. */
function PolicyRedirect() {
    const { id } = useParams();
    return <Navigate to={`/clients/${id}`} replace />;
}

function App() {
    return (
        <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
                element={
                    <RequireAuth>
                        <AppLayout />
                    </RequireAuth>
                }
            >
                <Route index element={<Suspense fallback={<Spinner />}><DashboardPage /></Suspense>} />
                {/* Leads = prospects (own table); Clients = policy records; /people/:id = a client's profile. */}
                <Route path="leads" element={<Suspense fallback={<Spinner />}><LeadsPage /></Suspense>} />
                <Route path="people/:id" element={<Suspense fallback={<Spinner />}><PersonDetailPage /></Suspense>} />
                <Route path="clients" element={<Suspense fallback={<Spinner />}><PoliciesPage /></Suspense>} />
                <Route path="clients/new" element={<Suspense fallback={<Spinner />}><PolicyFormPage /></Suspense>} />
                <Route path="clients/:id" element={<Suspense fallback={<Spinner />}><PolicyDetailPage /></Suspense>} />
                <Route path="clients/:id/edit" element={<Suspense fallback={<Spinner />}><PolicyFormPage key="edit" /></Suspense>} />
                <Route path="clients/:policyId/documents/:documentId/edit" element={<Suspense fallback={<Spinner />}><ClientDocumentEditorPage /></Suspense>} />
                <Route path="clients/:policyId/documents/:documentId/pdf-editor" element={<Suspense fallback={<Spinner />}><ClientPdfEditorPage /></Suspense>} />
                <Route path="policies" element={<Navigate to="/clients" replace />} />
                <Route path="policies/:id" element={<PolicyRedirect />} />
                <Route path="documents" element={<Suspense fallback={<Spinner />}><DocumentsPage /></Suspense>} />
                <Route path="documents/:id/pdf-editor" element={<Suspense fallback={<Spinner />}><PdfTemplateEditorPage /></Suspense>} />
                <Route path="products" element={<Suspense fallback={<Spinner />}><ProductsPage /></Suspense>} />
                <Route path="fund-types" element={<Suspense fallback={<Spinner />}><FundTypesPage /></Suspense>} />
                <Route path="calendar" element={<Suspense fallback={<Spinner />}><CalendarPage /></Suspense>} />
                <Route path="appointments" element={<Suspense fallback={<Spinner />}><AppointmentsPage /></Suspense>} />
                <Route path="reminders" element={<Suspense fallback={<Spinner />}><RemindersPage /></Suspense>} />
                <Route path="goals" element={<Suspense fallback={<Spinner />}><GoalsPage /></Suspense>} />
                <Route path="email-templates" element={<Suspense fallback={<Spinner />}><EmailTemplatesPage /></Suspense>} />
                <Route path="analytics" element={<Suspense fallback={<Spinner />}><AnalyticsPage /></Suspense>} />
                <Route path="automations" element={<RequireAuth admin><Suspense fallback={<Spinner />}><AutomationsPage /></Suspense></RequireAuth>} />
                <Route path="audit-logs" element={<RequireAuth admin><Suspense fallback={<Spinner />}><AuditLogsPage /></Suspense></RequireAuth>} />
                <Route path="profile" element={<Suspense fallback={<Spinner />}><ProfilePage /></Suspense>} />
                <Route path="*" element={<Suspense fallback={<Spinner />}><NotFoundPage /></Suspense>} />
            </Route>
        </Routes>
    );
}

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <ThemeProvider>
            <QueryClientProvider client={queryClient}>
                <BrowserRouter>
                    <AuthProvider>
                        <TooltipProvider>
                            <App />
                            <Toaster richColors position="top-right" />
                        </TooltipProvider>
                    </AuthProvider>
                </BrowserRouter>
            </QueryClientProvider>
        </ThemeProvider>
    </StrictMode>,
);
