import { Link, useNavigate } from 'react-router-dom';
import { Alert, Button, Card, Col, List, Row, Space, Statistic, Tag, Typography, message } from 'antd';
import { FileAddOutlined, PlusOutlined, ThunderboltOutlined, WalletOutlined } from '@ant-design/icons';
import { api } from '../api/client';
import { useGet, useSave } from '../api/hooks';
import { useAuth } from '../auth/AuthContext';
import Money, { statusColor } from '../components/Money';
import { fmtDate, fmtPeriod } from '../utils/date';
import { formatMoney } from '../utils/money';
import { useI18n } from '../i18n';

interface Dashboard {
  receivable: string;
  payable: string;
  top_dues: { party_id: number; name: string; balance: string; d90_plus: string }[];
  month: { billed: string; collected: string; spent: string };
  overdue: { count: number; amount: string };
  cash_bank: { id: number; name: string; balance: string }[];
  units: { total: number; occupied: number };
  bills_due: number;
  recent_invoices: { id: number; number: string; invoice_date: string; total: string; status: string; tenant_name: string }[];
}

interface Upcoming {
  due_now: { agreement_id: number; tenant_name: string; unit_code: string; period: string; taxable_amount: string }[];
  later_this_month: { agreement_id: number; tenant_name: string; unit_code: string; period: string; billing_date: string; taxable_amount: string }[];
}

export default function DashboardPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const { t } = useI18n();
  const dash = useGet<Dashboard>('/dashboard');
  const upcoming = useGet<Upcoming>('/agreements/upcoming-bills');
  const run = useSave(() => api.post<{ created: unknown[]; skipped: number; errors: unknown[] }>('/agreements/run-billing'), {
    invalidate: ['/invoices', '/agreements', '/dashboard'],
    onSuccess: (r) => {
      upcoming.refetch();
      message.success(`${r.created.length} ${t('dash.created')}${r.errors.length ? `, ${r.errors.length} ${t('dash.failed')}` : ''}`);
    },
  });
  const d = dash.data;
  const stat = (title: string, value: string | undefined, color?: string, to?: string) => (
    <Card size="small" hoverable={!!to} onClick={() => to && navigate(to)} loading={dash.isLoading}>
      <Statistic title={title} value={value ?? 0} formatter={(v) => formatMoney(String(v))} prefix="₹" valueStyle={{ color, fontSize: 20 }} />
    </Card>
  );

  return (
    <div className="page">
      <Space wrap style={{ marginBottom: 12 }}>
        <Button type="primary" icon={<FileAddOutlined />} onClick={() => navigate('/invoices/new')}>{t('dash.newBill')}</Button>
        <Button icon={<WalletOutlined />} onClick={() => navigate('/receipts/new')}>{t('dash.receive')}</Button>
        {can('accountant', 'staff') && <Button icon={<PlusOutlined />} onClick={() => navigate('/expenses/new')}>{t('dash.expense')}</Button>}
      </Space>

      {d && d.overdue.count > 0 && (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 12 }}
          message={<Link to="/reports/outstanding">{d.overdue.count} {t('dash.overdue')} ₹ {formatMoney(d.overdue.amount)}</Link>}
        />
      )}

      <Row gutter={[12, 12]}>
        <Col xs={12} md={6}>{stat(t('dash.toReceive'), d?.receivable, '#d63939', '/reports/outstanding')}</Col>
        <Col xs={12} md={6}>{stat(t('dash.billed'), d?.month.billed, undefined, '/invoices')}</Col>
        <Col xs={12} md={6}>{stat(t('dash.collected'), d?.month.collected, '#2fb344', '/receipts')}</Col>
        <Col xs={12} md={6}>{stat(t('dash.spent'), d?.month.spent, undefined, '/expenses')}</Col>
      </Row>

      <Row gutter={[12, 12]} style={{ marginTop: 12 }}>
        <Col xs={24} md={12}>
          <Card
            size="small"
            title={<Space><ThunderboltOutlined />{t('dash.rentDue')}</Space>}
            extra={can('accountant') && (upcoming.data?.due_now.length ?? 0) > 0 && (
              <Button size="small" type="primary" loading={run.isPending} onClick={() => run.mutate(undefined)}>{t('dash.generate')}</Button>
            )}
            loading={upcoming.isLoading}
          >
            {upcoming.data && upcoming.data.due_now.length === 0 && upcoming.data.later_this_month.length === 0 && (
              <Typography.Text type="secondary">{t('dash.allBilled')}</Typography.Text>
            )}
            <List
              size="small"
              dataSource={[...(upcoming.data?.due_now ?? []).map((u) => ({ ...u, when: t('dash.dueNow') })), ...(upcoming.data?.later_this_month ?? []).map((u) => ({ ...u, when: fmtDate(u.billing_date) }))]}
              renderItem={(u) => (
                <List.Item extra={<Money value={u.taxable_amount} />}>
                  <List.Item.Meta
                    title={<Link to={`/agreements/${u.agreement_id}`}>{u.tenant_name}</Link>}
                    description={`${u.unit_code} · ${fmtPeriod(u.period)} · ${u.when}`}
                  />
                </List.Item>
              )}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>{t('dash.auto')}</Typography.Text>
          </Card>
        </Col>
        <Col xs={24} md={12}>
          <Card size="small" title={t('dash.topDues')} extra={<Link to="/reports/outstanding">{t('dash.all')}</Link>} loading={dash.isLoading}>
            <List
              size="small"
              dataSource={d?.top_dues ?? []}
              locale={{ emptyText: t('dash.noDues') }}
              renderItem={(r) => (
                <List.Item extra={<Money value={r.balance} type="danger" />}>
                  <Link to={`/reports/party-ledger?party_type=tenant&party_id=${r.party_id}`}>{r.name}</Link>
                  {Number(r.d90_plus) > 0 && <Tag color="red" style={{ marginLeft: 8 }}>{t('dash.days90')}</Tag>}
                </List.Item>
              )}
            />
          </Card>
        </Col>
        <Col xs={24} md={12}>
          <Card size="small" title={t('dash.recent')} extra={<Link to="/invoices">{t('dash.all')}</Link>} loading={dash.isLoading}>
            <List
              size="small"
              dataSource={d?.recent_invoices ?? []}
              locale={{ emptyText: t('dash.noBills') }}
              renderItem={(i) => (
                <List.Item extra={<Money value={i.total} />} onClick={() => navigate(`/invoices/${i.id}`)} style={{ cursor: 'pointer' }}>
                  <List.Item.Meta title={`${i.number} · ${i.tenant_name}`} description={<Space>{fmtDate(i.invoice_date)}<Tag color={statusColor(i.status)}>{i.status}</Tag></Space>} />
                </List.Item>
              )}
            />
          </Card>
        </Col>
        <Col xs={24} md={12}>
          <Card size="small" title={t('dash.cash')} loading={dash.isLoading}>
            <List
              size="small"
              dataSource={d?.cash_bank ?? []}
              renderItem={(a) => (
                <List.Item extra={<Money value={a.balance} />}>
                  {can('accountant') ? <Link to={`/reports/ledger?account_id=${a.id}`}>{a.name}</Link> : a.name}
                </List.Item>
              )}
            />
            {d && (
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {t('dash.units')}: {d.units.occupied} / {d.units.total} · {t('dash.payable')}: ₹ {formatMoney(d.payable)}
              </Typography.Text>
            )}
          </Card>
        </Col>
      </Row>
    </div>
  );
}
