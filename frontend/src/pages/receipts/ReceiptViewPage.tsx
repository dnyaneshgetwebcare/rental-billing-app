import { Link, useNavigate, useParams } from 'react-router-dom';
import { Button, Card, Descriptions, Popconfirm, Space, Spin, Table, Tag } from 'antd';
import { WhatsAppOutlined } from '@ant-design/icons';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import Attachments from '../../components/Attachments';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';
import { formatMoney } from '../../utils/money';

interface Receipt {
  id: number;
  number: string;
  receipt_date: string;
  amount: string;
  unallocated_amount: string;
  mode: string;
  reference: string | null;
  notes: string | null;
  status: string;
  tenant: { id: number; name: string; phone: string | null; whatsapp: string | null };
  account: { name: string };
  allocations: { invoice_id: number; amount: string; number: string; invoice_date: string; total: string }[];
}

export default function ReceiptViewPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { can, company } = useAuth();
  const r = useGet<Receipt>(`/receipts/${id}`);
  const cancel = useSave(() => api.post(`/receipts/${id}/cancel`), { invalidate: ['/receipts', '/invoices'], success: 'Receipt cancelled' });

  if (!r.data) return <div className="page"><Spin /></div>;
  const d = r.data;
  const phone = (d.tenant.whatsapp || d.tenant.phone || '').replace(/\D/g, '');
  const text = `Dear ${d.tenant.name},\nReceived Rs. ${formatMoney(d.amount)} on ${fmtDate(d.receipt_date)} by ${d.mode.toUpperCase()}${d.reference ? ` (${d.reference})` : ''}.\nReceipt ${d.number}${d.allocations.length ? ` against ${d.allocations.map((a) => a.number).join(', ')}` : ''}.\nThank you,\n${company?.name ?? ''}`;

  return (
    <div className="page">
      <PageHeader
        title={<Space>{d.number}{d.status === 'cancelled' && <Tag>cancelled</Tag>}</Space>}
        back="/receipts"
        extra={
          <>
            {d.status === 'active' && (
              <Button icon={<WhatsAppOutlined />} href={`https://wa.me/${phone.length === 10 ? '91' + phone : phone}?text=${encodeURIComponent(text)}`} target="_blank" style={{ color: '#25D366', borderColor: '#25D366' }}>
                Send receipt
              </Button>
            )}
            {d.status === 'active' && can('accountant') && (
              <Popconfirm title="Cancel this receipt? Bills become due again." onConfirm={() => cancel.mutate(undefined)}>
                <Button danger loading={cancel.isPending}>Cancel</Button>
              </Popconfirm>
            )}
          </>
        }
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Tenant"><Link to={`/reports/party-ledger?party_type=tenant&party_id=${d.tenant.id}`}>{d.tenant.name}</Link></Descriptions.Item>
          <Descriptions.Item label="Date">{fmtDate(d.receipt_date)}</Descriptions.Item>
          <Descriptions.Item label="Amount"><Money value={d.amount} strong /></Descriptions.Item>
          <Descriptions.Item label="Mode">{d.mode.toUpperCase()}{d.reference && ` · ${d.reference}`}</Descriptions.Item>
          <Descriptions.Item label="Into">{d.account.name}</Descriptions.Item>
          {Number(d.unallocated_amount) > 0 && <Descriptions.Item label="Advance (not applied)"><Money value={d.unallocated_amount} /></Descriptions.Item>}
          {d.notes && <Descriptions.Item label="Notes">{d.notes}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card size="small" title="Applied to bills" style={{ marginBottom: 12 }}>
        <Table
          size="small"
          rowKey="invoice_id"
          pagination={false}
          dataSource={d.allocations}
          locale={{ emptyText: 'Not applied to any bill' }}
          onRow={(a) => ({ onClick: () => navigate(`/invoices/${a.invoice_id}`), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Bill', dataIndex: 'number' },
            { title: 'Date', dataIndex: 'invoice_date', render: fmtDate },
            { title: 'Bill total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} /> },
            { title: 'Applied', dataIndex: 'amount', align: 'right', render: (v) => <Money value={v} strong /> },
          ]}
        />
      </Card>
      <Attachments ownerType="receipt" ownerId={d.id} />
    </div>
  );
}
