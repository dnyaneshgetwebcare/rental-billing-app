import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Input, Select, Space, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useAuth } from '../../auth/AuthContext';
import DocList from '../../components/DocList';
import { DateRangeFilter, type Range } from '../../components/Filters';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';

export const VOUCHER_TYPES: Record<string, string> = {
  sales: 'Sales',
  credit_note: 'Credit note',
  receipt: 'Receipt',
  purchase: 'Purchase',
  expense: 'Expense',
  payment: 'Payment',
  journal: 'Journal',
  reversal: 'Reversal',
};

interface Row {
  id: number;
  number: string;
  voucher_type: string;
  voucher_date: string;
  narration: string | null;
  amount: string;
  is_reversed: number;
}

export default function VoucherListPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [type, setType] = useState<string>();
  const [range, setRange] = useState<Range>({});

  return (
    <div className="page">
      <PageHeader
        title="Vouchers"
        extra={can('accountant') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/vouchers/new')}>Journal entry</Button>}
      />
      <Space wrap style={{ marginBottom: 12 }}>
        <Input.Search allowClear placeholder="Number / narration" onSearch={(v) => { setQ(v); setPage(1); }} style={{ width: 200 }} />
        <Select allowClear placeholder="Type" value={type} onChange={(v) => { setType(v); setPage(1); }} style={{ width: 150 }} options={Object.entries(VOUCHER_TYPES).map(([value, label]) => ({ value, label }))} />
        <DateRangeFilter value={range} onChange={(r) => { setRange(r); setPage(1); }} />
      </Space>
      <DocList<Row>
        path="/vouchers"
        query={{ q, type, ...range }}
        page={page}
        onPage={setPage}
        perPage={50}
        onOpen={(r) => navigate(`/vouchers/${r.id}`)}
        columns={[
          { title: 'Number', dataIndex: 'number', width: 150 },
          { title: 'Date', dataIndex: 'voucher_date', render: fmtDate, width: 120 },
          { title: 'Type', dataIndex: 'voucher_type', width: 120, render: (t, r) => <>{VOUCHER_TYPES[t] ?? t}{r.is_reversed ? <Tag style={{ marginLeft: 6 }}>reversed</Tag> : null}</> },
          { title: 'Narration', dataIndex: 'narration' },
          { title: 'Amount', dataIndex: 'amount', align: 'right', render: (v) => <Money value={v} /> },
        ]}
        mobile={(r) => ({
          title: r.narration ?? r.number,
          subtitle: `${r.number} · ${VOUCHER_TYPES[r.voucher_type]} · ${fmtDate(r.voucher_date)}`,
          right: <Money value={r.amount} strong />,
        })}
      />
    </div>
  );
}
