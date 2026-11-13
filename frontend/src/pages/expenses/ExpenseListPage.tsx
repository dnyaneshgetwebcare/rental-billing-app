import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Descriptions, Drawer, Grid, Input, Popconfirm, Space, Spin, Tag } from 'antd';
import { PaperClipOutlined, PlusOutlined } from '@ant-design/icons';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import Attachments from '../../components/Attachments';
import DocList from '../../components/DocList';
import { DateRangeFilter, type Range } from '../../components/Filters';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { AccountSelect } from '../../components/Selects';
import { fmtDate } from '../../utils/date';

interface Row {
  id: number;
  expense_date: string;
  description: string;
  amount: string;
  total: string;
  payment_mode: string;
  reference: string | null;
  status: string;
  account_name: string;
  paid_from_name: string;
  attachment_count: number;
}

interface Expense extends Row {
  cgst: string;
  sgst: string;
  igst: string;
  gst_rate: string;
  vendor_name: string | null;
}

function ExpenseDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const screens = Grid.useBreakpoint();
  const { can } = useAuth();
  const e = useGet<Expense>(id ? `/expenses/${id}` : null);
  const cancel = useSave(() => api.post(`/expenses/${id}/cancel`), { invalidate: ['/expenses'], success: 'Expense cancelled' });
  const d = e.data;
  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      width={screens.md ? 520 : '100%'}
      title="Expense"
      extra={
        d?.status === 'active' && can('accountant') && (
          <Popconfirm title="Cancel this expense?" onConfirm={() => cancel.mutate(undefined)}>
            <Button danger loading={cancel.isPending}>Cancel</Button>
          </Popconfirm>
        )
      }
    >
      {!d ? <Spin /> : (
        <>
          <Descriptions size="small" column={1} bordered style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Date">{fmtDate(d.expense_date)}</Descriptions.Item>
            <Descriptions.Item label="Head">{d.account_name}</Descriptions.Item>
            <Descriptions.Item label="Description">{d.description}</Descriptions.Item>
            {d.vendor_name && <Descriptions.Item label="Vendor">{d.vendor_name}</Descriptions.Item>}
            <Descriptions.Item label="Amount"><Money value={d.amount} /></Descriptions.Item>
            {Number(d.gst_rate) > 0 && <Descriptions.Item label={`GST ${Number(d.gst_rate)}%`}><Money value={String(Number(d.cgst) + Number(d.sgst) + Number(d.igst))} /></Descriptions.Item>}
            <Descriptions.Item label="Total"><Money value={d.total} strong /></Descriptions.Item>
            <Descriptions.Item label="Paid from">{d.paid_from_name} · {d.payment_mode.toUpperCase()}{d.reference && ` · ${d.reference}`}</Descriptions.Item>
            {d.status === 'cancelled' && <Descriptions.Item label="Status"><Tag>cancelled</Tag></Descriptions.Item>}
          </Descriptions>
          <Attachments ownerType="expense" ownerId={d.id} />
        </>
      )}
    </Drawer>
  );
}

export default function ExpenseListPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [account, setAccount] = useState<number>();
  const [range, setRange] = useState<Range>({});
  const [open, setOpen] = useState<number | null>(null);

  return (
    <div className="page">
      <PageHeader title="Expenses" extra={can('accountant', 'staff') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/expenses/new')}>New expense</Button>} />
      <Space wrap style={{ marginBottom: 12 }}>
        <Input.Search allowClear placeholder="Description / ref" onSearch={(v) => { setQ(v); setPage(1); }} style={{ width: 200 }} />
        <AccountSelect type="expense" cashBank={false} allowClear value={account} onChange={(v) => { setAccount(v); setPage(1); }} style={{ width: 220 }} />
        <DateRangeFilter value={range} onChange={(r) => { setRange(r); setPage(1); }} />
      </Space>
      <DocList<Row>
        path="/expenses"
        query={{ q, account_id: account, ...range }}
        page={page}
        onPage={setPage}
        onOpen={(r) => setOpen(r.id)}
        columns={[
          { title: 'Date', dataIndex: 'expense_date', render: fmtDate, width: 120 },
          { title: 'Head', dataIndex: 'account_name' },
          { title: 'Description', dataIndex: 'description', render: (v, r) => <>{v}{r.attachment_count > 0 && <PaperClipOutlined style={{ marginLeft: 6, color: '#888' }} />}</> },
          { title: 'Paid from', dataIndex: 'paid_from_name' },
          { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} /> },
          { title: '', dataIndex: 'status', width: 100, render: (s) => s === 'cancelled' && <Tag>cancelled</Tag> },
        ]}
        mobile={(r) => ({
          title: r.description,
          subtitle: <>{fmtDate(r.expense_date)} · {r.account_name}{r.attachment_count > 0 && <PaperClipOutlined style={{ marginLeft: 6 }} />}</>,
          right: <><Money value={r.total} strong />{r.status === 'cancelled' && <div><Tag>cancelled</Tag></div>}</>,
        })}
      />
      <ExpenseDrawer id={open} onClose={() => setOpen(null)} />
    </div>
  );
}
