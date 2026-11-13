import { useSearchParams } from 'react-router-dom';
import { Button, Card, Empty, Segmented, Space, Typography } from 'antd';
import { WhatsAppOutlined } from '@ant-design/icons';
import { useGet } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import { DateRangeFilter } from '../../components/Filters';
import PageHeader from '../../components/PageHeader';
import { TenantSelect, VendorSelect } from '../../components/Selects';
import { DrCr, ExcelButton, StatementTable, type StatementData } from '../../components/Statement';
import { fmtDate } from '../../utils/date';
import { formatMoney } from '../../utils/money';

interface PartyStatement extends StatementData {
  party: { id: number; name: string; gstin: string | null; phone: string | null; whatsapp: string | null };
  party_type: 'tenant' | 'vendor';
}

/** Tenant / vendor statement across all accounts (bills, receipts, deposits). */
export default function PartyLedgerPage() {
  const { company } = useAuth();
  const [params, setParams] = useSearchParams();
  const partyType = (params.get('party_type') as 'tenant' | 'vendor') ?? 'tenant';
  const partyId = params.get('party_id') ? Number(params.get('party_id')) : undefined;
  const range = { from: params.get('from') ?? undefined, to: params.get('to') ?? undefined };
  const set = (patch: Record<string, string | number | undefined>) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === undefined || v === '') next.delete(k);
      else next.set(k, String(v));
    }
    setParams(next, { replace: true });
  };
  const q = { party_type: partyType, party_id: partyId, ...range };
  const data = useGet<PartyStatement>(partyId ? '/reports/party-ledger' : null, q);
  const d = data.data;
  const phone = (d?.party.whatsapp || d?.party.phone || '').replace(/\D/g, '');
  const closing = Number(d?.closing ?? 0);
  const reminder = d
    ? `Dear ${d.party.name},\nYour balance with ${company?.name} as on ${fmtDate(d.to)} is Rs. ${formatMoney(Math.abs(closing))} ${closing >= 0 ? 'due' : 'in advance'}.\nPlease ignore if already paid.`
    : '';

  return (
    <div className="page">
      <PageHeader
        title={d ? `Statement: ${d.party.name}` : 'Party statement'}
        back
        extra={
          partyId && (
            <>
              {partyType === 'tenant' && d && closing > 0 && (
                <Button icon={<WhatsAppOutlined />} href={`https://wa.me/${phone.length === 10 ? '91' + phone : phone}?text=${encodeURIComponent(reminder)}`} target="_blank" style={{ color: '#25D366', borderColor: '#25D366' }}>
                  Reminder
                </Button>
              )}
              <ExcelButton path="/reports/party-ledger" query={q} filename={`statement-${d?.party.name ?? partyId}.xlsx`} />
            </>
          )
        }
      />
      <Space wrap style={{ marginBottom: 12 }}>
        <Segmented value={partyType} onChange={(v) => set({ party_type: String(v), party_id: undefined })} options={[{ label: 'Tenant', value: 'tenant' }, { label: 'Vendor', value: 'vendor' }]} />
        {partyType === 'tenant' ? (
          <TenantSelect value={partyId} onChange={(v) => set({ party_id: v })} style={{ width: 240 }} />
        ) : (
          <VendorSelect value={partyId} onChange={(v) => set({ party_id: v })} style={{ width: 240 }} />
        )}
        <DateRangeFilter value={range} onChange={(r) => set({ from: r.from, to: r.to })} />
      </Space>
      {d && (
        <Typography.Paragraph>
          Closing balance: <b><DrCr value={d.closing} /></b>
          <Typography.Text type="secondary"> ({partyType === 'tenant' ? 'Dr = tenant owes you' : 'Cr = you owe the vendor'})</Typography.Text>
        </Typography.Paragraph>
      )}
      <Card size="small">
        {partyId ? <StatementTable data={d} loading={data.isFetching} showAccount /> : <Empty description="Choose a party" />}
      </Card>
    </div>
  );
}
