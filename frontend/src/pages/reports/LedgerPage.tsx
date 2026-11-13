import { useSearchParams } from 'react-router-dom';
import { Card, Empty, Space } from 'antd';
import { useGet } from '../../api/hooks';
import { DateRangeFilter } from '../../components/Filters';
import PageHeader from '../../components/PageHeader';
import { AccountSelect } from '../../components/Selects';
import { ExcelButton, StatementTable, type StatementData } from '../../components/Statement';

export default function LedgerPage() {
  const [params, setParams] = useSearchParams();
  const accountId = params.get('account_id') ? Number(params.get('account_id')) : undefined;
  const range = { from: params.get('from') ?? undefined, to: params.get('to') ?? undefined };
  const set = (patch: Record<string, string | number | undefined>) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === undefined || v === '') next.delete(k);
      else next.set(k, String(v));
    }
    setParams(next, { replace: true });
  };
  const data = useGet<StatementData & { account: { code: string; name: string } }>(accountId ? '/reports/ledger' : null, { account_id: accountId, ...range });

  return (
    <div className="page">
      <PageHeader
        title={data.data ? `Ledger: ${data.data.account.name}` : 'Ledger'}
        extra={accountId && <ExcelButton path="/reports/ledger" query={{ account_id: accountId, ...range }} filename={`ledger-${data.data?.account.code ?? accountId}.xlsx`} />}
      />
      <Space wrap style={{ marginBottom: 12 }}>
        <AccountSelect value={accountId} onChange={(v) => set({ account_id: v })} style={{ width: 280 }} />
        <DateRangeFilter value={range} onChange={(r) => set({ from: r.from, to: r.to })} />
      </Space>
      <Card size="small">
        {accountId ? <StatementTable data={data.data} loading={data.isFetching} showParty /> : <Empty description="Choose an account" />}
      </Card>
    </div>
  );
}
