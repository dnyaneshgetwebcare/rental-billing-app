import { Link, useNavigate, useParams } from 'react-router-dom';
import { Button, Card, Descriptions, Popconfirm, Space, Spin, Table, Tag } from 'antd';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { sourceLink } from '../../components/Statement';
import { fmtDate } from '../../utils/date';
import { VOUCHER_TYPES } from './VoucherListPage';

interface Voucher {
  id: number;
  number: string;
  voucher_type: string;
  voucher_date: string;
  narration: string | null;
  source_type: string | null;
  source_id: number | null;
  is_reversed: number;
  reverses_voucher_id: number | null;
  lines: { id: number; account_id: number; account_code: string; account_name: string; party_name: string | null; debit: string; credit: string; narration: string | null }[];
}

export default function VoucherViewPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const v = useGet<Voucher>(`/vouchers/${id}`);
  const reverse = useSave(() => api.post<{ id: number }>(`/vouchers/${id}/reverse`), {
    invalidate: ['/vouchers', '/reports'],
    success: 'Reversal posted',
    onSuccess: (r) => navigate(`/vouchers/${r.id}`),
  });

  if (!v.data) return <div className="page"><Spin /></div>;
  const d = v.data;
  const source = d.source_type && d.source_id ? sourceLink({ source_type: d.source_type, source_id: d.source_id, voucher_id: d.id }) : null;
  const total = d.lines.reduce((s, l) => s + Number(l.debit), 0);

  return (
    <div className="page">
      <PageHeader
        title={<Space>{d.number}<Tag>{VOUCHER_TYPES[d.voucher_type]}</Tag>{d.is_reversed ? <Tag color="red">reversed</Tag> : null}</Space>}
        back
        extra={
          d.voucher_type === 'journal' && !d.is_reversed && can('accountant') && (
            <Popconfirm title="Post a reversal of this journal dated today?" onConfirm={() => reverse.mutate(undefined)}>
              <Button danger loading={reverse.isPending}>Reverse</Button>
            </Popconfirm>
          )
        }
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Date">{fmtDate(d.voucher_date)}</Descriptions.Item>
          <Descriptions.Item label="Narration">{d.narration}</Descriptions.Item>
          {source && source !== `/vouchers/${d.id}` && <Descriptions.Item label="Document"><Link to={source}>Open {d.source_type?.replace('_', ' ')}</Link></Descriptions.Item>}
          {d.reverses_voucher_id && <Descriptions.Item label="Reverses"><Link to={`/vouchers/${d.reverses_voucher_id}`}>Original voucher</Link></Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card size="small">
        <Table
          size="small"
          rowKey="id"
          pagination={false}
          dataSource={d.lines}
          scroll={{ x: 560 }}
          onRow={(l) => ({ onClick: () => can('accountant') && navigate(`/reports/ledger?account_id=${l.account_id}`), style: { cursor: 'pointer' } })}
          summary={() => (
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={2}><b>Total</b></Table.Summary.Cell>
              <Table.Summary.Cell index={1} align="right"><Money value={String(total)} strong /></Table.Summary.Cell>
              <Table.Summary.Cell index={2} align="right"><Money value={String(total)} strong /></Table.Summary.Cell>
            </Table.Summary.Row>
          )}
          columns={[
            { title: 'Account', render: (_, l) => `${l.account_code} ${l.account_name}` },
            { title: 'Party', dataIndex: 'party_name' },
            { title: 'Debit', dataIndex: 'debit', align: 'right', render: (x) => (Number(x) ? <Money value={x} /> : '') },
            { title: 'Credit', dataIndex: 'credit', align: 'right', render: (x) => (Number(x) ? <Money value={x} /> : '') },
          ]}
        />
      </Card>
    </div>
  );
}
