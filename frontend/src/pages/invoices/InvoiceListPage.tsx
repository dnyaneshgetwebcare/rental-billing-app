import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Button, Input, Segmented, Space, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import DocList from '../../components/DocList';
import { DateRangeFilter, type Range } from '../../components/Filters';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { TenantSelect } from '../../components/Selects';
import { fmtDate, fmtPeriod } from '../../utils/date';

interface Row {
  id: number;
  number: string;
  invoice_date: string;
  due_date: string | null;
  period_label: string | null;
  total: string;
  balance: string;
  status: string;
  source: string;
  tenant_name: string;
}

export default function InvoiceListPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState(params.get('status') ?? '');
  const [range, setRange] = useState<Range>({});
  const [tenant, setTenant] = useState<number | undefined>(params.get('tenant') ? Number(params.get('tenant')) : undefined);
  const reset = () => setPage(1);

  return (
    <div className="page">
      <PageHeader
        title="Bills"
        extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/invoices/new')}>New bill</Button>}
      />
      <Space wrap style={{ marginBottom: 12 }}>
        <Segmented
          value={status}
          onChange={(v) => { setStatus(String(v)); reset(); }}
          options={[{ label: 'All', value: '' }, { label: 'Due', value: 'outstanding' }, { label: 'Paid', value: 'paid' }, { label: 'Cancelled', value: 'cancelled' }]}
        />
        <Input.Search allowClear placeholder="Number / tenant" onSearch={(v) => { setQ(v); reset(); }} style={{ width: 190 }} />
        <TenantSelect allowClear value={tenant} onChange={(v) => { setTenant(v); reset(); }} style={{ width: 200 }} />
        <DateRangeFilter value={range} onChange={(r) => { setRange(r); reset(); }} />
      </Space>
      <DocList<Row>
        path="/invoices"
        query={{ q, status, tenant_id: tenant, ...range }}
        page={page}
        onPage={setPage}
        onOpen={(r) => navigate(`/invoices/${r.id}`)}
        columns={[
          { title: 'Number', dataIndex: 'number', render: (v, r) => <>{v}{r.source === 'auto' && <Tag style={{ marginLeft: 6 }}>auto</Tag>}</> },
          { title: 'Date', dataIndex: 'invoice_date', render: fmtDate, width: 120 },
          { title: 'Tenant', dataIndex: 'tenant_name' },
          { title: 'Month', dataIndex: 'period_label', render: fmtPeriod, width: 100 },
          { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} /> },
          { title: 'Balance', dataIndex: 'balance', align: 'right', render: (v, r) => (r.status === 'cancelled' ? '-' : <Money value={v} type={Number(v) > 0 ? 'danger' : undefined} />) },
          { title: 'Status', dataIndex: 'status', width: 100, render: (s) => <Tag color={statusColor(s)}>{s}</Tag> },
        ]}
        mobile={(r) => ({
          title: r.tenant_name,
          subtitle: `${r.number} · ${fmtDate(r.invoice_date)}`,
          right: (
            <>
              <Money value={r.total} strong />
              <div><Tag color={statusColor(r.status)} style={{ marginRight: 0 }}>{r.status}</Tag></div>
            </>
          ),
        })}
      />
    </div>
  );
}
