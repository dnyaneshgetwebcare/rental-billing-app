import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Alert, Card, Col, DatePicker, Row, Space, Statistic, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useGet } from '../../api/hooks';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { ExcelButton } from '../../components/Statement';
import { formatMoney } from '../../utils/money';

interface TB {
  as_of: string;
  rows: { account_id: number; code: string; name: string; type: string; debit: string; credit: string }[];
  total_debit: string;
  total_credit: string;
  income: string;
  expense: string;
  profit: string;
}

const TYPE_COLORS: Record<string, string> = { asset: 'blue', liability: 'orange', equity: 'purple', income: 'green', expense: 'red' };

export default function TrialBalancePage() {
  const navigate = useNavigate();
  const [asOf, setAsOf] = useState(dayjs().format('YYYY-MM-DD'));
  const tb = useGet<TB>('/reports/trial-balance', { as_of: asOf });
  const d = tb.data;
  const balanced = d && d.total_debit === d.total_credit;

  return (
    <div className="page">
      <PageHeader title="Trial balance" extra={<ExcelButton path="/reports/trial-balance" query={{ as_of: asOf }} filename={`trial-balance-${asOf}.xlsx`} />} />
      <Space wrap style={{ marginBottom: 12 }}>
        As of <DatePicker value={dayjs(asOf)} onChange={(v) => v && setAsOf(v.format('YYYY-MM-DD'))} format="DD-MM-YYYY" allowClear={false} />
      </Space>
      {d && !balanced && <Alert type="error" showIcon message="Debits and credits do not match. Please contact support." style={{ marginBottom: 12 }} />}
      {d && (
        <Row gutter={12} style={{ marginBottom: 12 }}>
          <Col xs={8}><Card size="small"><Statistic title="Income" value={d.income} formatter={(v) => formatMoney(String(v))} /></Card></Col>
          <Col xs={8}><Card size="small"><Statistic title="Expenses" value={d.expense} formatter={(v) => formatMoney(String(v))} /></Card></Col>
          <Col xs={8}><Card size="small"><Statistic title="Profit" value={d.profit} formatter={(v) => formatMoney(String(v))} valueStyle={{ color: Number(d.profit) >= 0 ? '#389e0d' : '#cf1322' }} /></Card></Col>
        </Row>
      )}
      <Card size="small">
        <Table
          size="small"
          rowKey="account_id"
          loading={tb.isFetching}
          dataSource={d?.rows ?? []}
          pagination={false}
          scroll={{ x: 560 }}
          onRow={(r) => ({ onClick: () => navigate(`/reports/ledger?account_id=${r.account_id}&to=${asOf}`), style: { cursor: 'pointer' } })}
          summary={() =>
            d && (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={3}><b>Total</b></Table.Summary.Cell>
                <Table.Summary.Cell index={1} align="right"><Money value={d.total_debit} strong /></Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right"><Money value={d.total_credit} strong /></Table.Summary.Cell>
              </Table.Summary.Row>
            )
          }
          columns={[
            { title: 'Code', dataIndex: 'code', width: 80 },
            { title: 'Account', dataIndex: 'name' },
            { title: 'Type', dataIndex: 'type', width: 100, render: (t) => <Tag color={TYPE_COLORS[t]}>{t}</Tag> },
            { title: 'Debit', dataIndex: 'debit', align: 'right', render: (v) => (Number(v) ? <Money value={v} /> : '') },
            { title: 'Credit', dataIndex: 'credit', align: 'right', render: (v) => (Number(v) ? <Money value={v} /> : '') },
          ]}
        />
      </Card>
    </div>
  );
}
