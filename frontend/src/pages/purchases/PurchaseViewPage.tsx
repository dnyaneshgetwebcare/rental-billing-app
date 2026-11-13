import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button, Card, Descriptions, Popconfirm, Space, Spin, Table, Tag } from 'antd';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import Attachments from '../../components/Attachments';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';
import { STATES } from '../../utils/money';
import { LinesTable, TaxSummary, type DocLine } from '../invoices/InvoiceViewPage';
import { PayVendorModal } from './VendorPaymentsPage';

interface Purchase {
  id: number;
  bill_no: string;
  bill_date: string;
  place_of_supply: string;
  is_inter_state: number;
  taxable_amount: string;
  cgst: string;
  sgst: string;
  igst: string;
  round_off: string;
  total: string;
  paid_amount: string;
  balance: string;
  status: string;
  notes: string | null;
  vendor_id: number;
  vendor: { id: number; name: string; gstin: string | null };
  lines: (DocLine & { account_name: string })[];
  payments: { id: number; payment_date: string; amount: string; mode: string; reference: string | null; status: string; account_name: string }[];
}

export default function PurchaseViewPage() {
  const { id } = useParams();
  const { can } = useAuth();
  const [paying, setPaying] = useState(false);
  const p = useGet<Purchase>(`/purchases/${id}`);
  const cancel = useSave(() => api.post(`/purchases/${id}/cancel`), { invalidate: ['/purchases'], success: 'Purchase cancelled' });

  if (!p.data) return <div className="page"><Spin /></div>;
  const d = p.data;
  const open = d.status === 'unpaid' || d.status === 'partial';

  return (
    <div className="page">
      <PageHeader
        title={<Space>{d.vendor.name} · {d.bill_no}<Tag color={statusColor(d.status)}>{d.status}</Tag></Space>}
        back="/purchases"
        extra={
          <>
            {open && can('accountant') && <Button type="primary" onClick={() => setPaying(true)}>Pay</Button>}
            {d.status === 'unpaid' && can('accountant') && (
              <Popconfirm title="Cancel this purchase?" onConfirm={() => cancel.mutate(undefined)}>
                <Button danger loading={cancel.isPending}>Cancel</Button>
              </Popconfirm>
            )}
          </>
        }
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Vendor"><Link to={`/reports/party-ledger?party_type=vendor&party_id=${d.vendor.id}`}>{d.vendor.name}</Link>{d.vendor.gstin && ` · ${d.vendor.gstin}`}</Descriptions.Item>
          <Descriptions.Item label="Bill date">{fmtDate(d.bill_date)}</Descriptions.Item>
          <Descriptions.Item label="Vendor state">{STATES[d.place_of_supply] ?? d.place_of_supply}</Descriptions.Item>
          <Descriptions.Item label="Balance"><Money value={d.balance} strong /></Descriptions.Item>
          {d.notes && <Descriptions.Item label="Notes">{d.notes}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card size="small" style={{ marginBottom: 12 }}>
        <LinesTable lines={d.lines.map((l) => ({ ...l, description: `${l.description} (${l.account_name})` }))} interState={!!d.is_inter_state} />
        <TaxSummary d={d} />
      </Card>
      {d.payments.length > 0 && (
        <Card size="small" title="Payments" style={{ marginBottom: 12 }}>
          <Table
            size="small"
            rowKey="id"
            pagination={false}
            dataSource={d.payments}
            columns={[
              { title: 'Date', dataIndex: 'payment_date', render: fmtDate },
              { title: 'From', dataIndex: 'account_name' },
              { title: 'Mode', dataIndex: 'mode', render: (m, r) => `${m.toUpperCase()}${r.reference ? ' · ' + r.reference : ''}` },
              { title: 'Amount', dataIndex: 'amount', align: 'right', render: (v) => <Money value={v} /> },
              { title: '', dataIndex: 'status', render: (s) => s === 'cancelled' && <Tag>cancelled</Tag> },
            ]}
          />
        </Card>
      )}
      <Attachments ownerType="purchase" ownerId={d.id} />
      <PayVendorModal open={paying} onClose={() => setPaying(false)} vendorId={d.vendor_id} purchaseId={d.id} amount={d.balance} />
    </div>
  );
}
