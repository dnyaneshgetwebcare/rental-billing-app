import { Link, useParams } from 'react-router-dom';
import { Button, Card, Descriptions, Popconfirm, Space, Spin, Tag } from 'antd';
import { FilePdfOutlined } from '@ant-design/icons';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';
import { openPdf } from '../../utils/files';
import { LinesTable, TaxSummary, type DocLine } from './InvoiceViewPage';

interface CreditNote {
  id: number;
  number: string;
  note_date: string;
  reason: string;
  status: string;
  is_inter_state: number;
  taxable_amount: string;
  cgst: string;
  sgst: string;
  igst: string;
  round_off: string;
  total: string;
  invoice: { id: number; number: string };
  tenant: { id: number; name: string; gstin: string | null };
  lines: DocLine[];
}

export default function CreditNoteViewPage() {
  const { id } = useParams();
  const { can } = useAuth();
  const cn = useGet<CreditNote>(`/credit-notes/${id}`);
  const cancel = useSave(() => api.post(`/credit-notes/${id}/cancel`), { invalidate: ['/credit-notes', '/invoices'], success: 'Credit note cancelled' });

  if (!cn.data) return <div className="page"><Spin /></div>;
  const d = cn.data;
  return (
    <div className="page">
      <PageHeader
        title={<Space>{d.number}<Tag color={statusColor(d.status)}>{d.status}</Tag></Space>}
        back="/credit-notes"
        extra={
          <>
            <Button icon={<FilePdfOutlined />} onClick={() => openPdf(`/credit-notes/${d.id}/pdf`, `CreditNote-${d.number}.pdf`)}>PDF</Button>
            {d.status === 'active' && can('accountant') && (
              <Popconfirm title="Cancel this credit note?" onConfirm={() => cancel.mutate(undefined)}>
                <Button danger loading={cancel.isPending}>Cancel</Button>
              </Popconfirm>
            )}
          </>
        }
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Tenant">{d.tenant.name}</Descriptions.Item>
          <Descriptions.Item label="Date">{fmtDate(d.note_date)}</Descriptions.Item>
          <Descriptions.Item label="Against bill"><Link to={`/invoices/${d.invoice.id}`}>{d.invoice.number}</Link></Descriptions.Item>
          <Descriptions.Item label="Reason">{d.reason}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card size="small">
        <LinesTable lines={d.lines} interState={!!d.is_inter_state} />
        <TaxSummary d={d} />
      </Card>
    </div>
  );
}
