import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Input, Segmented, Space, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useAuth } from '../../auth/AuthContext';
import DocList from '../../components/DocList';
import { DateRangeFilter, type Range } from '../../components/Filters';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';

interface Row {
  id: number;
  bill_no: string;
  bill_date: string;
  vendor_name: string;
  total: string;
  balance: string;
  status: string;
}

export default function PurchaseListPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [range, setRange] = useState<Range>({});

  return (
    <div className="page">
      <PageHeader title="Purchases" extra={can('accountant') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/purchases/new')}>New purchase</Button>} />
      <Space wrap style={{ marginBottom: 12 }}>
        <Segmented value={status} onChange={(v) => { setStatus(String(v)); setPage(1); }} options={[{ label: 'All', value: '' }, { label: 'To pay', value: 'outstanding' }, { label: 'Paid', value: 'paid' }]} />
        <Input.Search allowClear placeholder="Bill no / vendor" onSearch={(v) => { setQ(v); setPage(1); }} style={{ width: 200 }} />
        <DateRangeFilter value={range} onChange={(r) => { setRange(r); setPage(1); }} />
      </Space>
      <DocList<Row>
        path="/purchases"
        query={{ q, status, ...range }}
        page={page}
        onPage={setPage}
        onOpen={(r) => navigate(`/purchases/${r.id}`)}
        columns={[
          { title: 'Vendor', dataIndex: 'vendor_name' },
          { title: 'Bill no.', dataIndex: 'bill_no' },
          { title: 'Date', dataIndex: 'bill_date', render: fmtDate, width: 120 },
          { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} /> },
          { title: 'Balance', dataIndex: 'balance', align: 'right', render: (v, r) => (r.status === 'cancelled' ? '-' : <Money value={v} />) },
          { title: 'Status', dataIndex: 'status', width: 100, render: (s) => <Tag color={statusColor(s)}>{s}</Tag> },
        ]}
        mobile={(r) => ({
          title: r.vendor_name,
          subtitle: `${r.bill_no} · ${fmtDate(r.bill_date)}`,
          right: <><Money value={r.total} strong /><div><Tag color={statusColor(r.status)} style={{ marginRight: 0 }}>{r.status}</Tag></div></>,
        })}
      />
    </div>
  );
}
