import { useNavigate } from 'react-router-dom';
import { Button, Table, Typography } from 'antd';
import { FileExcelOutlined } from '@ant-design/icons';
import Money from './Money';
import { fmtDate } from '../utils/date';
import { download } from '../utils/files';

export interface StatementLine {
  voucher_id: number;
  voucher_date: string;
  number: string;
  voucher_type: string;
  source_type: string | null;
  source_id: number | null;
  narration: string | null;
  account_name: string;
  party_name: string | null;
  debit: string;
  credit: string;
  balance: string;
}

export interface StatementData {
  from: string;
  to: string;
  opening: string;
  lines: StatementLine[];
  total_debit: string;
  total_credit: string;
  closing: string;
}

const SOURCE_ROUTES: Record<string, string> = {
  invoice: '/invoices',
  credit_note: '/credit-notes',
  receipt: '/receipts',
  purchase: '/purchases',
};

export function sourceLink(l: Pick<StatementLine, 'source_type' | 'source_id' | 'voucher_id'>): string {
  const base = l.source_type ? SOURCE_ROUTES[l.source_type] : undefined;
  return base && l.source_id ? `${base}/${l.source_id}` : `/vouchers/${l.voucher_id}`;
}

/** Dr/Cr balance display: positive = debit. */
export function DrCr({ value }: { value: string }) {
  const n = Number(value);
  if (n === 0) return <Money value="0" />;
  return <><Money value={String(Math.abs(n))} /> <Typography.Text type="secondary">{n > 0 ? 'Dr' : 'Cr'}</Typography.Text></>;
}

export function StatementTable({ data, loading, showAccount, showParty }: { data?: StatementData; loading?: boolean; showAccount?: boolean; showParty?: boolean }) {
  const navigate = useNavigate();
  const rows = data
    ? [
        { key: 'open', narration: 'Opening balance', balance: data.opening } as Partial<StatementLine> & { key: string },
        ...data.lines.map((l, i) => ({ ...l, key: `${l.voucher_id}-${i}` })),
      ]
    : [];
  return (
    <Table
      size="small"
      rowKey="key"
      loading={loading}
      dataSource={rows}
      pagination={false}
      scroll={{ x: 760 }}
      onRow={(r) => ({ onClick: () => r.voucher_id && navigate(sourceLink(r as StatementLine)), style: { cursor: r.voucher_id ? 'pointer' : undefined } })}
      summary={() =>
        data && (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0} colSpan={2 + (showAccount ? 1 : 0) + (showParty ? 1 : 0) + 1}><b>Total / closing</b></Table.Summary.Cell>
            <Table.Summary.Cell index={1} align="right"><Money value={data.total_debit} strong /></Table.Summary.Cell>
            <Table.Summary.Cell index={2} align="right"><Money value={data.total_credit} strong /></Table.Summary.Cell>
            <Table.Summary.Cell index={3} align="right"><b><DrCr value={data.closing} /></b></Table.Summary.Cell>
          </Table.Summary.Row>
        )
      }
      columns={[
        { title: 'Date', dataIndex: 'voucher_date', render: fmtDate, width: 110 },
        { title: 'Voucher', dataIndex: 'number', width: 140 },
        ...(showAccount ? [{ title: 'Account', dataIndex: 'account_name' }] : []),
        ...(showParty ? [{ title: 'Party', dataIndex: 'party_name' }] : []),
        { title: 'Particulars', dataIndex: 'narration' },
        { title: 'Debit', dataIndex: 'debit', align: 'right' as const, render: (v?: string) => (v && Number(v) ? <Money value={v} /> : '') },
        { title: 'Credit', dataIndex: 'credit', align: 'right' as const, render: (v?: string) => (v && Number(v) ? <Money value={v} /> : '') },
        { title: 'Balance', dataIndex: 'balance', align: 'right' as const, render: (v?: string) => (v !== undefined ? <DrCr value={v} /> : '') },
      ]}
    />
  );
}

export function ExcelButton({ path, query, filename }: { path: string; query: Record<string, string | number | undefined>; filename: string }) {
  return (
    <Button icon={<FileExcelOutlined />} onClick={() => download(path, filename, { ...query, format: 'xlsx' })}>
      Excel
    </Button>
  );
}
