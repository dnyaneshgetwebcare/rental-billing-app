import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, DatePicker, Space, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useGet } from '../../api/hooks';
import Money, { statusColor } from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { ExcelButton } from '../../components/Statement';
import { fmtDate } from '../../utils/date';

type Totals = Record<'taxable_amount' | 'cgst' | 'sgst' | 'igst' | 'round_off' | 'total', string>;

interface Register {
  rows: ({ id: number; number: string; invoice_date: string; tenant_name: string; gstin: string | null; place_of_supply: string; status: string } & Totals)[];
  totals: Totals;
}

export default function SalesRegisterPage() {
  const navigate = useNavigate();
  const [month, setMonth] = useState(dayjs().format('YYYY-MM'));
  const data = useGet<Register>('/reports/sales-register', { month });
  const t = data.data?.totals;
  const m = (v: string) => <Money value={v} />;

  return (
    <div className="page">
      <PageHeader title="Sales register" extra={<ExcelButton path="/reports/sales-register" query={{ month }} filename={`sales-register-${month}.xlsx`} />} />
      <Space style={{ marginBottom: 12 }}>
        <DatePicker picker="month" value={dayjs(`${month}-01`)} onChange={(v) => v && setMonth(v.format('YYYY-MM'))} format="MMM YYYY" allowClear={false} />
      </Space>
      <Card size="small">
        <Table
          size="small"
          rowKey="id"
          loading={data.isFetching}
          dataSource={data.data?.rows ?? []}
          pagination={false}
          scroll={{ x: 900 }}
          onRow={(r) => ({ onClick: () => navigate(`/invoices/${r.id}`), style: { cursor: 'pointer' } })}
          summary={() =>
            t && (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={4}><b>Total</b></Table.Summary.Cell>
                {(['taxable_amount', 'cgst', 'sgst', 'igst', 'total'] as const).map((k, i) => (
                  <Table.Summary.Cell key={k} index={i + 1} align="right"><Money value={t[k]} strong /></Table.Summary.Cell>
                ))}
                <Table.Summary.Cell index={7} />
              </Table.Summary.Row>
            )
          }
          columns={[
            { title: 'Bill', dataIndex: 'number' },
            { title: 'Date', dataIndex: 'invoice_date', render: fmtDate },
            { title: 'Tenant', dataIndex: 'tenant_name' },
            { title: 'GSTIN', dataIndex: 'gstin' },
            { title: 'Taxable', dataIndex: 'taxable_amount', align: 'right', render: m },
            { title: 'CGST', dataIndex: 'cgst', align: 'right', render: m },
            { title: 'SGST', dataIndex: 'sgst', align: 'right', render: m },
            { title: 'IGST', dataIndex: 'igst', align: 'right', render: m },
            { title: 'Total', dataIndex: 'total', align: 'right', render: m },
            { title: 'Status', dataIndex: 'status', render: (s) => <Tag color={statusColor(s)}>{s}</Tag> },
          ]}
        />
      </Card>
    </div>
  );
}
