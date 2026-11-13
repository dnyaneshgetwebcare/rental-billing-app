import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Input, Segmented, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useAuth } from '../../auth/AuthContext';
import DocList from '../../components/DocList';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';

export interface AgreementRow {
  id: number;
  agreement_no: string | null;
  tenant_id: number;
  tenant_name: string;
  unit_code: string;
  warehouse_name: string;
  start_date: string;
  end_date: string | null;
  rent_amount: string;
  billing_day: number;
  auto_bill: number;
  status: string;
}

export default function AgreementListPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('active');

  return (
    <div className="page">
      <PageHeader
        title="Agreements"
        extra={
          <>
            <Input.Search allowClear placeholder="Tenant / unit" onSearch={(v) => { setQ(v); setPage(1); }} style={{ width: 200 }} />
            <Segmented value={status} onChange={(v) => { setStatus(String(v)); setPage(1); }} options={[{ label: 'Active', value: 'active' }, { label: 'Ended', value: 'ended' }, { label: 'All', value: '' }]} />
            {can('accountant') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/agreements/new')}>New</Button>}
          </>
        }
      />
      <DocList<AgreementRow>
        path="/agreements"
        query={{ q, status }}
        page={page}
        onPage={setPage}
        onOpen={(r) => navigate(`/agreements/${r.id}`)}
        columns={[
          { title: 'Tenant', dataIndex: 'tenant_name' },
          { title: 'Unit', render: (_, r) => `${r.warehouse_name} / ${r.unit_code}` },
          { title: 'From', dataIndex: 'start_date', render: fmtDate, width: 120 },
          { title: 'To', dataIndex: 'end_date', render: fmtDate, width: 120 },
          { title: 'Rent / month', dataIndex: 'rent_amount', align: 'right', render: (v) => <Money value={v} /> },
          { title: 'Bill day', dataIndex: 'billing_day', width: 90, render: (v, r) => (r.auto_bill ? v : <Tag>manual</Tag>) },
          { title: 'Status', dataIndex: 'status', width: 100, render: (s) => <Tag color={statusColor(s)}>{s}</Tag> },
        ]}
        mobile={(r) => ({
          title: r.tenant_name,
          subtitle: `${r.warehouse_name} / ${r.unit_code} · from ${fmtDate(r.start_date)}`,
          right: (
            <>
              <Money value={r.rent_amount} strong />
              <div><Tag color={statusColor(r.status)} style={{ marginRight: 0 }}>{r.status}</Tag></div>
            </>
          ),
        })}
      />
    </div>
  );
}
