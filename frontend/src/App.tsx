import { lazy, type ReactNode } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { Spin } from 'antd';
import { useAuth } from './auth/AuthContext';
import AppLayout from './layouts/AppLayout';
import LoginPage from './pages/LoginPage';
import CompanySelectPage from './pages/CompanySelectPage';
import CompanyFormPage from './pages/CompanyFormPage';

const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const MorePage = lazy(() => import('./pages/MorePage'));
const PasswordPage = lazy(() => import('./pages/PasswordPage'));
const MasterPage = lazy(() => import('./pages/masters/MasterPage'));
const UsersPage = lazy(() => import('./pages/settings/UsersPage'));
const AuditLogPage = lazy(() => import('./pages/settings/AuditLogPage'));
const AgreementListPage = lazy(() => import('./pages/agreements/AgreementListPage'));
const AgreementFormPage = lazy(() => import('./pages/agreements/AgreementFormPage'));
const AgreementViewPage = lazy(() => import('./pages/agreements/AgreementViewPage'));
const InvoiceListPage = lazy(() => import('./pages/invoices/InvoiceListPage'));
const InvoiceFormPage = lazy(() => import('./pages/invoices/InvoiceFormPage'));
const InvoiceViewPage = lazy(() => import('./pages/invoices/InvoiceViewPage'));
const CreditNoteListPage = lazy(() => import('./pages/invoices/CreditNoteListPage'));
const CreditNoteViewPage = lazy(() => import('./pages/invoices/CreditNoteViewPage'));
const ReceiptListPage = lazy(() => import('./pages/receipts/ReceiptListPage'));
const ReceiptFormPage = lazy(() => import('./pages/receipts/ReceiptFormPage'));
const ReceiptViewPage = lazy(() => import('./pages/receipts/ReceiptViewPage'));
const PurchaseListPage = lazy(() => import('./pages/purchases/PurchaseListPage'));
const PurchaseFormPage = lazy(() => import('./pages/purchases/PurchaseFormPage'));
const PurchaseViewPage = lazy(() => import('./pages/purchases/PurchaseViewPage'));
const VendorPaymentsPage = lazy(() => import('./pages/purchases/VendorPaymentsPage'));
const ExpenseListPage = lazy(() => import('./pages/expenses/ExpenseListPage'));
const ExpenseFormPage = lazy(() => import('./pages/expenses/ExpenseFormPage'));
const VoucherListPage = lazy(() => import('./pages/accounting/VoucherListPage'));
const VoucherFormPage = lazy(() => import('./pages/accounting/VoucherFormPage'));
const VoucherViewPage = lazy(() => import('./pages/accounting/VoucherViewPage'));
const LedgerPage = lazy(() => import('./pages/reports/LedgerPage'));
const TrialBalancePage = lazy(() => import('./pages/reports/TrialBalancePage'));
const OutstandingPage = lazy(() => import('./pages/reports/OutstandingPage'));
const GstSummaryPage = lazy(() => import('./pages/reports/GstSummaryPage'));
const SalesRegisterPage = lazy(() => import('./pages/reports/SalesRegisterPage'));
const PartyLedgerPage = lazy(() => import('./pages/reports/PartyLedgerPage'));

function RequireCompany({ children }: { children: ReactNode }) {
  const { ready, user, company } = useAuth();
  const location = useLocation();
  if (!ready) return <div style={{ padding: 64, textAlign: 'center' }}><Spin size="large" /></div>;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  if (!company) return <Navigate to="/select-company" replace />;
  return <>{children}</>;
}

function RequireUser({ children }: { children: ReactNode }) {
  const { ready, user } = useAuth();
  if (!ready) return <div style={{ padding: 64, textAlign: 'center' }}><Spin size="large" /></div>;
  if (!user) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/select-company" element={<RequireUser><CompanySelectPage /></RequireUser>} />
      <Route path="/companies/new" element={<RequireUser><CompanyFormPage mode="new" /></RequireUser>} />

      <Route element={<RequireCompany><AppLayout /></RequireCompany>}>
        <Route index element={<DashboardPage />} />
        <Route path="more" element={<MorePage />} />
        <Route path="settings/password" element={<PasswordPage />} />
        <Route path="settings/company" element={<CompanyFormPage mode="edit" />} />
        <Route path="settings/users" element={<UsersPage />} />
        <Route path="settings/audit-log" element={<AuditLogPage />} />

        {(['tenants', 'vendors', 'warehouses', 'units', 'tax-rates', 'accounts'] as const).map((r) => (
          <Route key={r} path={r} element={<MasterPage resource={r} />} />
        ))}

        <Route path="agreements" element={<AgreementListPage />} />
        <Route path="agreements/new" element={<AgreementFormPage />} />
        <Route path="agreements/:id" element={<AgreementViewPage />} />
        <Route path="agreements/:id/edit" element={<AgreementFormPage />} />

        <Route path="invoices" element={<InvoiceListPage />} />
        <Route path="invoices/new" element={<InvoiceFormPage />} />
        <Route path="invoices/:id" element={<InvoiceViewPage />} />
        <Route path="credit-notes" element={<CreditNoteListPage />} />
        <Route path="credit-notes/:id" element={<CreditNoteViewPage />} />

        <Route path="receipts" element={<ReceiptListPage />} />
        <Route path="receipts/new" element={<ReceiptFormPage />} />
        <Route path="receipts/:id" element={<ReceiptViewPage />} />

        <Route path="purchases" element={<PurchaseListPage />} />
        <Route path="purchases/new" element={<PurchaseFormPage />} />
        <Route path="purchases/:id" element={<PurchaseViewPage />} />
        <Route path="vendor-payments" element={<VendorPaymentsPage />} />
        <Route path="expenses" element={<ExpenseListPage />} />
        <Route path="expenses/new" element={<ExpenseFormPage />} />

        <Route path="vouchers" element={<VoucherListPage />} />
        <Route path="vouchers/new" element={<VoucherFormPage />} />
        <Route path="vouchers/:id" element={<VoucherViewPage />} />

        <Route path="reports/ledger" element={<LedgerPage />} />
        <Route path="reports/party-ledger" element={<PartyLedgerPage />} />
        <Route path="reports/trial-balance" element={<TrialBalancePage />} />
        <Route path="reports/outstanding" element={<OutstandingPage />} />
        <Route path="reports/gst" element={<GstSummaryPage />} />
        <Route path="reports/sales-register" element={<SalesRegisterPage />} />

        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}
