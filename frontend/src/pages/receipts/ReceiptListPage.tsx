import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Input, Space, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useAuth } from '../../auth/AuthContext';
import DocList from '../../components/DocList';
import { DateRangeFilter, type Range } from '../../components/Filters';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { TenantSelect } from '../../components/Selects';
import { fmtDate } from '../../utils/date';

interface Row {
  id: number;
  number: string;
  receipt_date: string;
  amount: string;
  unallocated_amount: string;
  mode: string;
  reference: string | null;
  status: string;
  tenant_name: string;
  account_name: string;
}

export default function ReceiptListPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [tenant, setTenant] = useState<number>();
  const [range, setRange] = useState<Range>({});

  return (
    <div className="page">
      <PageHeader
        title="Receipts"
        extra={can('accountant', 'staff') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/receipts/new')}>Receive payment</Button>}
      />
      <Space wrap style={{ marginBottom: 12 }}>
        <Input.Search allowClear placeholder="Number / tenant / UTR" onSearch={(v) => { setQ(v); setPage(1); }} style={{ width: 200 }} />
        <TenantSelect allowClear value={tenant} onChange={(v) => { setTenant(v); setPage(1); }} style={{ width: 200 }} />
        <DateRangeFilter value={range} onChange={(r) => { setRange(r); setPage(1); }} />
      </Space>
      <DocList<Row>
        path="/receipts"
        query={{ q, tenant_id: tenant, ...range }}
        page={page}
        onPage={setPage}
        onOpen={(r) => navigate(`/receipts/${r.id}`)}
        columns={[
          { title: 'Number', dataIndex: 'number' },
          { title: 'Date', dataIndex: 'receipt_date', render: fmtDate, width: 120 },
          { title: 'Tenant', dataIndex: 'tenant_name' },
          { title: 'Mode', dataIndex: 'mode', render: (m, r) => <>{m.toUpperCase()}{r.reference && <span style={{ color: '#888' }}> · {r.reference}</span>}</> },
          { title: 'Into', dataIndex: 'account_name' },
          { title: 'Amount', dataIndex: 'amount', align: 'right', render: (v, r) => <>{<Money value={v} />}{Number(r.unallocated_amount) > 0 && <div><Tag color="blue">advance <Money value={r.unallocated_amount} /></Tag></div>}</> },
          { title: '', dataIndex: 'status', width: 100, render: (s) => s === 'cancelled' && <Tag>cancelled</Tag> },
        ]}
        mobile={(r) => ({
          title: r.tenant_name,
          subtitle: `${r.number} · ${fmtDate(r.receipt_date)} · ${r.mode.toUpperCase()}`,
          right: <><Money value={r.amount} strong type={r.status === 'cancelled' ? 'secondary' : 'success'} />{r.status === 'cancelled' && <div><Tag>cancelled</Tag></div>}</>,
        })}
      />
    </div>
  );
}
