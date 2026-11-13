import { useState } from 'react';
import { Alert, Card, DatePicker, Descriptions, Space, Table, Tabs } from 'antd';
import dayjs from 'dayjs';
import { useGet } from '../../api/hooks';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { ExcelButton } from '../../components/Statement';
import { fmtDate } from '../../utils/date';
import { STATES } from '../../utils/money';

type Tax = { taxable_amount?: string; cgst: string; sgst: string; igst: string; total?: string };

interface Gst {
  gstr1: {
    b2b: ({ id: number; number: string; invoice_date: string; gstin: string; tenant_name: string; place_of_supply: string; gst_rate: string; invoice_value: string } & Tax)[];
    b2c: ({ place_of_supply: string; gst_rate: string } & Tax)[];
    credit_notes: ({ id: number; number: string; note_date: string; invoice_number: string; gstin: string | null; tenant_name: string } & Tax)[];
    hsn: ({ sac: string; gst_rate: string } & Tax)[];
  };
  gstr3b: { output: Tax; itc: Tax & { purchases: Tax; expenses: Tax }; net: Tax };
}

const m = (v: string) => <Money value={v} />;
const taxCols = [
  { title: 'Taxable', dataIndex: 'taxable_amount', align: 'right' as const, render: m },
  { title: 'CGST', dataIndex: 'cgst', align: 'right' as const, render: m },
  { title: 'SGST', dataIndex: 'sgst', align: 'right' as const, render: m },
  { title: 'IGST', dataIndex: 'igst', align: 'right' as const, render: m },
];
const pos = (s: string) => `${s} - ${STATES[s] ?? ''}`;
const rate = (r: string) => `${Number(r)}%`;

export default function GstSummaryPage() {
  const [month, setMonth] = useState(dayjs().format('YYYY-MM'));
  const data = useGet<Gst>('/reports/gst-summary', { month });
  const d = data.data;

  const row = (label: string, t?: Tax) => (
    <Descriptions.Item label={label}>
      {t && <Space wrap size="large"><span>CGST {m(t.cgst)}</span><span>SGST {m(t.sgst)}</span><span>IGST {m(t.igst)}</span>{t.total !== undefined && <b>Total {m(t.total)}</b>}</Space>}
    </Descriptions.Item>
  );

  return (
    <div className="page">
      <PageHeader title="GST summary" extra={<ExcelButton path="/reports/gst-summary" query={{ month }} filename={`gst-${month}.xlsx`} />} />
      <Space style={{ marginBottom: 12 }}>
        <DatePicker picker="month" value={dayjs(`${month}-01`)} onChange={(v) => v && setMonth(v.format('YYYY-MM'))} format="MMM YYYY" allowClear={false} />
      </Space>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Figures to help you file GSTR-1 and GSTR-3B. Check them with your CA before filing; set-off rules are not applied here." />
      <Card size="small" title="GSTR-3B" loading={data.isLoading} style={{ marginBottom: 12 }}>
        {d && (
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Outward taxable value">{m(d.gstr3b.output.taxable_amount ?? '0')}</Descriptions.Item>
            {row('Output tax', d.gstr3b.output)}
            {row('ITC on purchases', d.gstr3b.itc.purchases)}
            {row('ITC on expenses', d.gstr3b.itc.expenses)}
            {row('Total ITC', d.gstr3b.itc)}
            {row('Net payable', d.gstr3b.net)}
          </Descriptions>
        )}
      </Card>
      <Card size="small" title="GSTR-1">
        <Tabs
          items={[
            {
              key: 'b2b',
              label: `B2B (${d?.gstr1.b2b.length ?? 0})`,
              children: (
                <Table
                  size="small"
                  rowKey={(r) => `${r.id}-${r.gst_rate}`}
                  dataSource={d?.gstr1.b2b ?? []}
                  pagination={false}
                  scroll={{ x: 900 }}
                  columns={[
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    { title: 'Receiver', dataIndex: 'tenant_name' },
                    { title: 'Invoice', dataIndex: 'number' },
                    { title: 'Date', dataIndex: 'invoice_date', render: fmtDate },
                    { title: 'Value', dataIndex: 'invoice_value', align: 'right', render: m },
                    { title: 'POS', dataIndex: 'place_of_supply', render: pos },
                    { title: 'Rate', dataIndex: 'gst_rate', render: rate },
                    ...taxCols,
                  ]}
                />
              ),
            },
            {
              key: 'b2c',
              label: 'B2C',
              children: (
                <Table
                  size="small"
                  rowKey={(r) => `${r.place_of_supply}-${r.gst_rate}`}
                  dataSource={d?.gstr1.b2c ?? []}
                  pagination={false}
                  columns={[{ title: 'POS', dataIndex: 'place_of_supply', render: pos }, { title: 'Rate', dataIndex: 'gst_rate', render: rate }, ...taxCols]}
                />
              ),
            },
            {
              key: 'cn',
              label: `Credit notes (${d?.gstr1.credit_notes.length ?? 0})`,
              children: (
                <Table
                  size="small"
                  rowKey="id"
                  dataSource={d?.gstr1.credit_notes ?? []}
                  pagination={false}
                  scroll={{ x: 800 }}
                  columns={[
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    { title: 'Receiver', dataIndex: 'tenant_name' },
                    { title: 'Note', dataIndex: 'number' },
                    { title: 'Date', dataIndex: 'note_date', render: fmtDate },
                    { title: 'Invoice', dataIndex: 'invoice_number' },
                    ...taxCols,
                  ]}
                />
              ),
            },
            {
              key: 'hsn',
              label: 'HSN / SAC',
              children: (
                <Table
                  size="small"
                  rowKey={(r) => `${r.sac}-${r.gst_rate}`}
                  dataSource={d?.gstr1.hsn ?? []}
                  pagination={false}
                  columns={[{ title: 'SAC', dataIndex: 'sac' }, { title: 'Rate', dataIndex: 'gst_rate', render: rate }, ...taxCols]}
                />
              ),
            },
          ]}
        />
      </Card>
    </div>
  );
}
