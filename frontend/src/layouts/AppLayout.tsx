import { Suspense, type ReactNode } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Button, Dropdown, Grid, Spin } from 'antd';
import {
  AppstoreOutlined,
  BankOutlined,
  DashboardOutlined,
  FileTextOutlined,
  LogoutOutlined,
  ShopOutlined,
  SwapOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { useAuth } from '../auth/AuthContext';
import { LanguageSwitch, useI18n, type MessageKey } from '../i18n';

export const NAV: { id: string; label: MessageKey; items: { to: string; label: MessageKey }[] }[] = [
  {
    id: 'Billing',
    label: 'nav.billing',
    items: [
      { to: '/invoices', label: 'nav.invoices' },
      { to: '/receipts', label: 'nav.receipts' },
      { to: '/credit-notes', label: 'nav.creditNotes' },
      { to: '/agreements', label: 'nav.agreements' },
    ],
  },
  {
    id: 'Masters',
    label: 'nav.masters',
    items: [
      { to: '/tenants', label: 'nav.tenants' },
      { to: '/warehouses', label: 'nav.warehouses' },
      { to: '/units', label: 'nav.units' },
      { to: '/vendors', label: 'nav.vendors' },
    ],
  },
  {
    id: 'Expenses',
    label: 'nav.expenses',
    items: [
      { to: '/purchases', label: 'nav.purchases' },
      { to: '/vendor-payments', label: 'nav.vendorPayments' },
      { to: '/expenses', label: 'nav.expenses' },
    ],
  },
  {
    id: 'Accounts',
    label: 'nav.accounts',
    items: [
      { to: '/vouchers', label: 'nav.vouchers' },
      { to: '/accounts', label: 'nav.chart' },
      { to: '/reports/ledger', label: 'nav.ledger' },
      { to: '/reports/party-ledger', label: 'nav.party' },
      { to: '/reports/trial-balance', label: 'nav.trial' },
      { to: '/reports/outstanding', label: 'nav.outstanding' },
      { to: '/reports/gst', label: 'nav.gst' },
      { to: '/reports/sales-register', label: 'nav.sales' },
    ],
  },
  {
    id: 'Settings',
    label: 'nav.settings',
    items: [
      { to: '/settings/company', label: 'nav.company' },
      { to: '/tax-rates', label: 'nav.taxRates' },
      { to: '/settings/users', label: 'nav.users' },
      { to: '/settings/audit-log', label: 'nav.audit' },
    ],
  },
];

const ICONS: Record<string, ReactNode> = {
  Billing: <FileTextOutlined />,
  Masters: <TeamOutlined />,
  Expenses: <ShopOutlined />,
  Accounts: <BankOutlined />,
  Settings: <AppstoreOutlined />,
};

function UserMenu() {
  const { user, company, companies, logout } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  return (
    <Dropdown
      trigger={['click']}
      menu={{
        items: [
          { key: 'who', label: `${user?.name} (${company?.role})`, disabled: true },
          ...(companies.length > 1 ? [{ key: 'switch', icon: <SwapOutlined />, label: t('nav.switch') }] : []),
          { key: 'password', icon: <UserOutlined />, label: t('nav.password') },
          { key: 'logout', icon: <LogoutOutlined />, label: t('nav.logout'), danger: true },
        ],
        onClick: async ({ key }) => {
          if (key === 'switch') navigate('/select-company');
          if (key === 'password') navigate('/settings/password');
          if (key === 'logout') {
            await logout();
            navigate('/login');
          }
        },
      }}
    >
      <Button type="text" icon={<UserOutlined />}>{company?.name}</Button>
    </Dropdown>
  );
}

export default function AppLayout() {
  const screens = Grid.useBreakpoint();
  const mobile = !screens.md;
  const location = useLocation();
  const { company } = useAuth();
  const { t } = useI18n();

  const content = (
    <Suspense fallback={<div style={{ padding: 48, textAlign: 'center' }}><Spin /></div>}>
      <Outlet />
    </Suspense>
  );

  if (mobile) {
    const tabs: { to: string; label: MessageKey; icon: ReactNode; match: (p: string) => boolean }[] = [
      { to: '/', label: 'nav.home', icon: <DashboardOutlined />, match: (p) => p === '/' },
      { to: '/invoices', label: 'nav.bills', icon: <FileTextOutlined />, match: (p) => p.startsWith('/invoices') },
      { to: '/tenants', label: 'nav.tenants', icon: <TeamOutlined />, match: (p) => p.startsWith('/tenants') },
      { to: '/more', label: 'nav.more', icon: <AppstoreOutlined />, match: (p) => p === '/more' },
    ];
    return (
      <div className="app-shell mobile">
        <div className="main-col">
          <header className="topbar no-print">
            <Link to="/" className="brand-inline">{company?.name ?? t('app.name')}</Link>
            <div className="topbar-actions">
              <LanguageSwitch />
              <UserMenu />
            </div>
          </header>
          <div className="with-tabbar">{content}</div>
          <nav className="mobile-tabbar no-print">
            {tabs.map((tab) => (
              <NavLink key={tab.to} to={tab.to} className={tab.match(location.pathname) ? 'active' : ''}>
                {tab.icon}
                {t(tab.label)}
              </NavLink>
            ))}
          </nav>
        </div>
      </div>
    );
  }

  return (
    <div className="app-shell">
      <aside className="side no-print">
        <Link to="/" className="brand">{t('app.name')}</Link>
        <nav>
          <NavLink to="/" end className="nav-item">{<DashboardOutlined />} {t('nav.dashboard')}</NavLink>
          {NAV.map((group) => (
            <div key={group.id}>
              <div className="nav-label">{ICONS[group.id]} {t(group.label)}</div>
              {group.items.map((item) => (
                <NavLink key={item.to} to={item.to} className="nav-item">{t(item.label)}</NavLink>
              ))}
            </div>
          ))}
        </nav>
      </aside>
      <div className="main-col">
        <header className="topbar no-print">
          <span className="gstin">{company?.gstin ? `GSTIN ${company.gstin}` : ''}</span>
          <div className="topbar-actions">
            <LanguageSwitch />
            <UserMenu />
          </div>
        </header>
        {content}
      </div>
    </div>
  );
}
