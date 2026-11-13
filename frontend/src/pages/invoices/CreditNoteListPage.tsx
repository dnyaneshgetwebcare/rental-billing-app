import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Tag, Typography } from 'antd';
import DocList from '../../components/DocList';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { fmtDate } from '../../utils/date';

interface Row {
  id: number;
  number: string;
  note_date: string;
  reason: string;
  total: string;
  status: string;
  invoice_number: string;
  tenant_name: string;
}

export default function CreditNoteListPage() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  return (
    <div className="page">
      <PageHeader title="Credit notes" />
      <Typography.Paragraph type="secondary">Issue a credit note from the bill page (discounts, wrong amount after payment).</Typography.Paragraph>
      <DocList<Row>
        path="/credit-notes"
        page={page}
        onPage={setPage}
        onOpen={(r) => navigate(`/credit-notes/${r.id}`)}
        columns={[
          { title: 'Number', dataIndex: 'number' },
          { title: 'Date', dataIndex: 'note_date', render: fmtDate },
          { title: 'Tenant', dataIndex: 'tenant_name' },
          { title: 'Against bill', dataIndex: 'invoice_number' },
          { title: 'Reason', dataIndex: 'reason' },
          { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => <Money value={v} /> },
          { title: 'Status', dataIndex: 'status', render: (s) => <Tag color={statusColor(s)}>{s}</Tag> },
        ]}
        mobile={(r) => ({
          title: r.tenant_name,
          subtitle: `${r.number} · ${fmtDate(r.note_date)} · ${r.reason}`,
          right: <><Money value={r.total} strong />{r.status === 'cancelled' && <div><Tag>cancelled</Tag></div>}</>,
        })}
      />
    </div>
  );
}
