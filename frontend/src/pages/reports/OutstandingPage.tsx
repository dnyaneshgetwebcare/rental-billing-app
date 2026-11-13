import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, DatePicker, Grid, List, Segmented, Space, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useGet } from '../../api/hooks';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { ExcelButton } from '../../components/Statement';

interface Row {
  party_id: number;
  name: string;
  phone: string | null;
  balance: string;
  not_due: string;
  d0_30: string;
  d31_60: string;
  d61_90: string;
  d90_plus: string;
}

const amount = (v: string) => (Number(v) ? <Money value={v} /> : '');

export default function OutstandingPage() {
  const navigate = useNavigate();
  const screens = Grid.useBreakpoint();
  const [type, setType] = useState<'tenant' | 'vendor'>('tenant');
  const [asOf, setAsOf] = useState(dayjs().format('YYYY-MM-DD'));
  const data = useGet<{ rows: Row[]; total: string }>('/reports/outstanding', { party_type: type, as_of: asOf });
  const rows = data.data?.rows ?? [];
  const open = (r: Row) => navigate(`/reports/party-ledger?party_type=${type}&party_id=${r.party_id}`);

  return (
    <div className="page">
      <PageHeader title="Outstanding" extra={<ExcelButton path="/reports/outstanding" query={{ party_type: type, as_of: asOf }} filename={`outstanding-${type}-${asOf}.xlsx`} />} />
      <Space wrap style={{ marginBottom: 12 }}>
        <Segmented value={type} onChange={(v) => setType(v as 'tenant' | 'vendor')} options={[{ label: 'To receive', value: 'tenant' }, { label: 'To pay', value: 'vendor' }]} />
        <DatePicker value={dayjs(asOf)} onChange={(v) => v && setAsOf(v.format('YYYY-MM-DD'))} format="DD-MM-YYYY" allowClear={false} />
        <span>Total: <Money value={data.data?.total ?? '0'} strong type={type === 'tenant' ? 'danger' : undefined} /></span>
      </Space>
      {screens.md ? (
        <Card size="small">
          <Table
            size="small"
            rowKey="party_id"
            loading={data.isFetching}
            dataSource={rows}
            pagination={false}
            onRow={(r) => ({ onClick: () => open(r), style: { cursor: 'pointer' } })}
            columns={[
              { title: type === 'tenant' ? 'Tenant' : 'Vendor', dataIndex: 'name' },
              { title: 'Phone', dataIndex: 'phone' },
              { title: 'Not due', dataIndex: 'not_due', align: 'right', render: amount },
              { title: '0-30', dataIndex: 'd0_30', align: 'right', render: amount },
              { title: '31-60', dataIndex: 'd31_60', align: 'right', render: amount },
              { title: '61-90', dataIndex: 'd61_90', align: 'right', render: amount },
              { title: '90+', dataIndex: 'd90_plus', align: 'right', render: (v) => (Number(v) ? <Money value={v} type="danger" /> : '') },
              { title: 'Balance', dataIndex: 'balance', align: 'right', render: (v) => <Money value={v} strong /> },
            ]}
          />
        </Card>
      ) : (
        <List
          loading={data.isFetching}
          dataSource={rows}
          renderItem={(r) => (
            <Card className="list-card" style={{ marginBottom: 8 }} onClick={() => open(r)}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <div>
                  <div style={{ fontWeight: 600 }}>{r.name}</div>
                  {Number(r.d90_plus) > 0 && <Tag color="red">90+ days: <Money value={r.d90_plus} /></Tag>}
                </div>
                <Money value={r.balance} strong type={Number(r.balance) > 0 ? 'danger' : 'success'} />
              </div>
            </Card>
          )}
        />
      )}
    </div>
  );
}
