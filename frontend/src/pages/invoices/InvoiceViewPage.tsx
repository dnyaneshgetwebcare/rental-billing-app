import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Button, Card, Descriptions, Form, Input, InputNumber, Modal, Space, Spin, Table, Tag, Typography } from 'antd';
import { FilePdfOutlined } from '@ant-design/icons';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import Attachments from '../../components/Attachments';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { GstRateSelect } from '../../components/Selects';
import ShareInvoice from '../../components/ShareInvoice';
import { fmtDate, fmtPeriod } from '../../utils/date';
import { openPdf } from '../../utils/files';
import { STATES } from '../../utils/money';

export interface DocLine {
  id: number;
  description: string;
  sac: string | null;
  qty: string;
  rate: string;
  gst_rate: string;
  taxable_amount: string;
  cgst: string;
  sgst: string;
  igst: string;
  total: string;
}

export interface InvoiceDetail {
  id: number;
  number: string;
  invoice_date: string;
  due_date: string | null;
  period_label: string | null;
  place_of_supply: string;
  is_inter_state: number;
  taxable_amount: string;
  cgst: string;
  sgst: string;
  igst: string;
  round_off: string;
  total: string;
  paid_amount: string;
  credited_amount: string;
  balance: string;
  status: string;
  source: string;
  notes: string | null;
  cancel_reason: string | null;
  agreement_id: number | null;
  tenant_id: number;
  tenant: { id: number; name: string; gstin: string | null; phone: string | null; whatsapp: string | null; address: string | null };
  unit?: { code: string; warehouse_name: string };
  lines: DocLine[];
  receipts: { id: number; number: string; receipt_date: string; mode: string; status: string; amount: string }[];
  credit_notes: { id: number; number: string; note_date: string; reason: string; total: string; status: string }[];
}

export function LinesTable({ lines, interState }: { lines: DocLine[]; interState: boolean }) {
  return (
    <Table
      size="small"
      rowKey="id"
      pagination={false}
      dataSource={lines}
      scroll={{ x: 560 }}
      columns={[
        { title: 'Description', dataIndex: 'description', render: (v, l) => <>{v}{l.sac && <div style={{ color: '#888', fontSize: 12 }}>SAC {l.sac}</div>}</> },
        { title: 'Qty', dataIndex: 'qty', align: 'right', render: (v) => Number(v), width: 60 },
        { title: 'Rate', dataIndex: 'rate', align: 'right', render: (v) => <Money value={v} /> },
        { title: 'Taxable', dataIndex: 'taxable_amount', align: 'right', render: (v) => <Money value={v} /> },
        { title: 'GST', align: 'right', render: (_, l) => <>{Number(l.gst_rate)}%<div><Money value={interState ? l.igst : String(Number(l.cgst) + Number(l.sgst))} /></div></> },
        { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} strong /> },
      ]}
    />
  );
}

export function TaxSummary({ d }: { d: { taxable_amount: string; cgst: string; sgst: string; igst: string; round_off: string; total: string; is_inter_state: number } }) {
  return (
    <Descriptions size="small" column={1} bordered style={{ maxWidth: 340, marginLeft: 'auto', marginTop: 12 }}>
      <Descriptions.Item label="Taxable"><Money value={d.taxable_amount} /></Descriptions.Item>
      {d.is_inter_state ? (
        <Descriptions.Item label="IGST"><Money value={d.igst} /></Descriptions.Item>
      ) : (
        <>
          <Descriptions.Item label="CGST"><Money value={d.cgst} /></Descriptions.Item>
          <Descriptions.Item label="SGST"><Money value={d.sgst} /></Descriptions.Item>
        </>
      )}
      {Number(d.round_off) !== 0 && <Descriptions.Item label="Round off"><Money value={d.round_off} /></Descriptions.Item>}
      <Descriptions.Item label="Total"><Money value={d.total} strong /></Descriptions.Item>
    </Descriptions>
  );
}

export default function InvoiceViewPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cnOpen, setCnOpen] = useState(false);
  const [cancelForm] = Form.useForm();
  const [cnForm] = Form.useForm();
  const inv = useGet<InvoiceDetail>(`/invoices/${id}`);

  const cancel = useSave((v: { reason: string }) => api.post(`/invoices/${id}/cancel`, v), {
    form: cancelForm,
    invalidate: ['/invoices', '/agreements'],
    success: 'Bill cancelled',
    onSuccess: () => setCancelOpen(false),
  });
  const creditNote = useSave(
    (v: { reason: string; amount: number; gst_rate: string }) =>
      api.post<{ id: number }>('/credit-notes', {
        invoice_id: Number(id),
        reason: v.reason,
        lines: [{ description: v.reason, qty: '1', rate: String(v.amount), gst_rate: v.gst_rate }],
      }),
    { form: cnForm, invalidate: ['/invoices', '/credit-notes'], success: 'Credit note issued', onSuccess: (r) => navigate(`/credit-notes/${r.id}`) },
  );

  if (!inv.data) return <div className="page"><Spin /></div>;
  const d = inv.data;
  const open = d.status === 'unpaid' || d.status === 'partial';

  return (
    <div className="page">
      <PageHeader
        title={<Space>{d.number}<Tag color={statusColor(d.status)}>{d.status}</Tag></Space>}
        back="/invoices"
        extra={
          <>
            <Button icon={<FilePdfOutlined />} onClick={() => openPdf(`/invoices/${d.id}/pdf`, `Invoice-${d.number}.pdf`)}>PDF</Button>
            {d.status !== 'cancelled' && <ShareInvoice invoiceId={d.id} number={d.number} />}
            {open && can('accountant', 'staff') && (
              <Button type="primary" onClick={() => navigate(`/receipts/new?tenant=${d.tenant_id}&invoice=${d.id}`)}>Receive payment</Button>
            )}
            {open && can('accountant') && <Button onClick={() => { cnForm.setFieldsValue({ gst_rate: d.lines[0]?.gst_rate ?? '18.00' }); setCnOpen(true); }}>Credit note</Button>}
            {d.status === 'unpaid' && Number(d.credited_amount) === 0 && can('accountant') && <Button danger onClick={() => setCancelOpen(true)}>Cancel</Button>}
          </>
        }
      />

      <Card size="small" style={{ marginBottom: 12 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Tenant">
            <Link to={`/reports/party-ledger?party_type=tenant&party_id=${d.tenant.id}`}>{d.tenant.name}</Link>
            {d.tenant.gstin && <Typography.Text type="secondary"> · {d.tenant.gstin}</Typography.Text>}
          </Descriptions.Item>
          <Descriptions.Item label="Date">{fmtDate(d.invoice_date)}{d.due_date && ` · due ${fmtDate(d.due_date)}`}</Descriptions.Item>
          {d.unit && <Descriptions.Item label="Unit"><Link to={`/agreements/${d.agreement_id}`}>{d.unit.warehouse_name} / {d.unit.code}</Link></Descriptions.Item>}
          {d.period_label && <Descriptions.Item label="Rent month">{fmtPeriod(d.period_label)}</Descriptions.Item>}
          <Descriptions.Item label="Place of supply">{STATES[d.place_of_supply] ?? d.place_of_supply} ({d.is_inter_state ? 'IGST' : 'CGST + SGST'})</Descriptions.Item>
          <Descriptions.Item label="Created">{d.source === 'auto' ? 'Automatically' : 'Manually'}</Descriptions.Item>
          {d.cancel_reason && <Descriptions.Item label="Cancel reason">{d.cancel_reason}</Descriptions.Item>}
          {d.notes && <Descriptions.Item label="Notes">{d.notes}</Descriptions.Item>}
        </Descriptions>
      </Card>

      <Card size="small" style={{ marginBottom: 12 }}>
        <LinesTable lines={d.lines} interState={!!d.is_inter_state} />
        <TaxSummary d={d} />
        {d.status !== 'cancelled' && (
          <Descriptions size="small" column={1} style={{ maxWidth: 340, marginLeft: 'auto', marginTop: 8 }}>
            <Descriptions.Item label="Received"><Money value={d.paid_amount} type="success" /></Descriptions.Item>
            {Number(d.credited_amount) > 0 && <Descriptions.Item label="Credited"><Money value={d.credited_amount} /></Descriptions.Item>}
            <Descriptions.Item label="Balance"><Money value={d.balance} strong type={Number(d.balance) > 0 ? 'danger' : undefined} /></Descriptions.Item>
          </Descriptions>
        )}
      </Card>

      {(d.receipts.length > 0 || d.credit_notes.length > 0) && (
        <Card size="small" title="Payments & credit notes" style={{ marginBottom: 12 }}>
          <Table
            size="small"
            rowKey={(r) => `${r.kind}-${r.id}`}
            pagination={false}
            dataSource={[
              ...d.receipts.map((r) => ({ kind: 'receipt', id: r.id, number: r.number, date: r.receipt_date, detail: r.mode, amount: r.amount, status: r.status })),
              ...d.credit_notes.map((c) => ({ kind: 'credit-note', id: c.id, number: c.number, date: c.note_date, detail: c.reason, amount: c.total, status: c.status })),
            ]}
            onRow={(r) => ({ onClick: () => navigate(r.kind === 'receipt' ? `/receipts/${r.id}` : `/credit-notes/${r.id}`), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Number', dataIndex: 'number' },
              { title: 'Date', dataIndex: 'date', render: fmtDate },
              { title: 'Detail', dataIndex: 'detail' },
              { title: 'Amount', dataIndex: 'amount', align: 'right', render: (v) => <Money value={v} /> },
              { title: '', dataIndex: 'status', render: (s) => s === 'cancelled' && <Tag>cancelled</Tag> },
            ]}
          />
        </Card>
      )}

      <Attachments ownerType="invoice" ownerId={d.id} />

      <Modal title="Cancel bill" open={cancelOpen} onCancel={() => setCancelOpen(false)} onOk={() => cancelForm.submit()} confirmLoading={cancel.isPending} okButtonProps={{ danger: true }} okText="Cancel bill">
        <p>The bill number is kept and its accounting entry is reversed.</p>
        <Form form={cancelForm} layout="vertical" onFinish={cancel.mutate}>
          <Form.Item name="reason" label="Reason" rules={[{ required: true }]}><Input /></Form.Item>
        </Form>
      </Modal>

      <Modal title="Issue credit note" open={cnOpen} onCancel={() => setCnOpen(false)} onOk={() => cnForm.submit()} confirmLoading={creditNote.isPending}>
        <Form form={cnForm} layout="vertical" onFinish={creditNote.mutate}>
          <Form.Item name="reason" label="Reason" rules={[{ required: true }]}><Input placeholder="e.g. Rent discount for October" /></Form.Item>
          <Space>
            <Form.Item name="amount" label="Amount before GST" rules={[{ required: true }]}><InputNumber min={0} style={{ width: 160 }} /></Form.Item>
            <Form.Item name="gst_rate" label="GST"><GstRateSelect style={{ width: 100 }} /></Form.Item>
          </Space>
          <Typography.Text type="secondary">Balance due on this bill: <Money value={d.balance} /></Typography.Text>
        </Form>
      </Modal>
    </div>
  );
}
