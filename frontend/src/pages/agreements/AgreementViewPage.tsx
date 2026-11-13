import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Button, Card, DatePicker, Descriptions, Modal, Space, Spin, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useGet, useList, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import Attachments from '../../components/Attachments';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate, fmtPeriod, toApiDate } from '../../utils/date';
import { STATES } from '../../utils/money';

export interface AgreementDetail {
  id: number;
  tenant_id: number;
  tenant_name: string;
  unit_id: number;
  unit_code: string;
  warehouse_name: string;
  property_state: string | null;
  agreement_no: string | null;
  start_date: string;
  end_date: string | null;
  rent_amount: string;
  current_rent: string;
  gst_rate: string;
  sac: string;
  billing_day: number;
  due_days: number;
  escalation_pct: string;
  escalation_months: number;
  deposit_amount: string;
  auto_bill: number;
  status: string;
  notes: string | null;
  charges: { id: number; description: string; amount: string; gst_rate: string; sac: string }[];
}

export default function AgreementViewPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const [ending, setEnding] = useState(false);
  const [endDate, setEndDate] = useState(dayjs());
  const a = useGet<AgreementDetail>(`/agreements/${id}`);
  const invoices = useList<{ id: number; number: string; invoice_date: string; period_label: string | null; total: string; balance: string; status: string }>(
    '/invoices',
    { agreement_id: id, per_page: 100 },
  );
  const end = useSave(() => api.post(`/agreements/${id}/end`, { end_date: toApiDate(endDate) }), {
    invalidate: ['/agreements', '/units'],
    success: 'Agreement ended',
    onSuccess: () => setEnding(false),
  });

  if (!a.data) return <div className="page"><Spin /></div>;
  const d = a.data;

  return (
    <div className="page">
      <PageHeader
        title={<Space>{d.tenant_name}<Tag color={statusColor(d.status)}>{d.status}</Tag></Space>}
        back="/agreements"
        extra={
          <>
            <Button type="primary" onClick={() => navigate(`/invoices/new?agreement=${d.id}`)}>New bill</Button>
            {can('accountant') && <Button onClick={() => navigate(`/agreements/${d.id}/edit`)}>Edit</Button>}
            {can('accountant') && d.status === 'active' && <Button danger onClick={() => setEnding(true)}>End</Button>}
          </>
        }
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Unit">{d.warehouse_name} / {d.unit_code}</Descriptions.Item>
          <Descriptions.Item label="Property state">{d.property_state ? STATES[d.property_state] : '-'}</Descriptions.Item>
          <Descriptions.Item label="Period">{fmtDate(d.start_date)} – {d.end_date ? fmtDate(d.end_date) : 'open'}</Descriptions.Item>
          <Descriptions.Item label="Agreement no.">{d.agreement_no ?? '-'}</Descriptions.Item>
          <Descriptions.Item label="Rent (base)"><Money value={d.rent_amount} /> + {Number(d.gst_rate)}% GST</Descriptions.Item>
          <Descriptions.Item label="Rent this month"><Money value={d.current_rent} strong /></Descriptions.Item>
          <Descriptions.Item label="Increase">{Number(d.escalation_pct) ? `${Number(d.escalation_pct)}% every ${d.escalation_months} months` : 'None'}</Descriptions.Item>
          <Descriptions.Item label="Billing">{d.auto_bill ? `Auto on day ${d.billing_day}` : 'Manual'}, due in {d.due_days} days</Descriptions.Item>
          <Descriptions.Item label="Deposit"><Money value={d.deposit_amount} /></Descriptions.Item>
          {d.notes && <Descriptions.Item label="Notes">{d.notes}</Descriptions.Item>}
        </Descriptions>
        {d.charges.length > 0 && (
          <Table
            size="small"
            rowKey="id"
            pagination={false}
            style={{ marginTop: 8 }}
            dataSource={d.charges}
            columns={[
              { title: 'Other charge', dataIndex: 'description' },
              { title: 'Amount', dataIndex: 'amount', align: 'right', render: (v) => <Money value={v} /> },
              { title: 'GST', dataIndex: 'gst_rate', render: (v) => `${Number(v)}%` },
            ]}
          />
        )}
      </Card>

      <Card size="small" title="Bills" style={{ marginBottom: 12 }}>
        <Table
          size="small"
          rowKey="id"
          loading={invoices.isFetching}
          dataSource={invoices.data?.items ?? []}
          pagination={false}
          scroll={{ x: 500 }}
          onRow={(r) => ({ onClick: () => navigate(`/invoices/${r.id}`), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Number', dataIndex: 'number' },
            { title: 'Month', dataIndex: 'period_label', render: fmtPeriod },
            { title: 'Date', dataIndex: 'invoice_date', render: fmtDate },
            { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} /> },
            { title: 'Status', dataIndex: 'status', render: (s) => <Tag color={statusColor(s)}>{s}</Tag> },
          ]}
        />
      </Card>

      <Attachments ownerType="agreement" ownerId={d.id} />

      <Modal title="End agreement" open={ending} onCancel={() => setEnding(false)} onOk={() => end.mutate(undefined)} confirmLoading={end.isPending} okText="End agreement" okButtonProps={{ danger: true }}>
        <p>Billing stops after this date and the unit becomes free.</p>
        <DatePicker value={endDate} onChange={(v) => v && setEndDate(v)} format="DD-MM-YYYY" />
      </Modal>
    </div>
  );
}
