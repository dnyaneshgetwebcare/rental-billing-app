import { Select, type SelectProps } from 'antd';
import { useOptions } from '../api/hooks';
import { stateOptions } from '../utils/money';

type P = Omit<SelectProps, 'options'>;

interface Named {
  id: number;
  name: string;
}

export function StateSelect(props: P) {
  return <Select showSearch optionFilterProp="label" options={stateOptions} placeholder="State" {...props} />;
}

export function TenantSelect(props: P) {
  const { rows, isLoading } = useOptions<Named & { gstin: string | null; phone: string | null }>('/tenants');
  return (
    <Select
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Tenant"
      options={rows.map((t) => ({ value: t.id, label: `${t.name}${t.phone ? ' · ' + t.phone : ''}` }))}
      {...props}
    />
  );
}

export function VendorSelect(props: P) {
  const { rows, isLoading } = useOptions<Named>('/vendors');
  return <Select showSearch optionFilterProp="label" loading={isLoading} placeholder="Vendor" options={rows.map((v) => ({ value: v.id, label: v.name }))} {...props} />;
}

export function WarehouseSelect(props: P) {
  const { rows, isLoading } = useOptions<Named>('/warehouses');
  return <Select showSearch optionFilterProp="label" loading={isLoading} placeholder="Warehouse" options={rows.map((w) => ({ value: w.id, label: w.name }))} {...props} />;
}

export function UnitSelect({ onlyFree, ...props }: P & { onlyFree?: boolean }) {
  const { rows, isLoading } = useOptions<{ id: number; code: string; warehouse_name: string; active_agreement_id: number | null }>('/units');
  return (
    <Select
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Unit"
      options={rows.map((u) => ({
        value: u.id,
        label: `${u.warehouse_name} / ${u.code}${u.active_agreement_id ? ' (occupied)' : ''}`,
        disabled: onlyFree && u.active_agreement_id !== null && u.id !== props.value,
      }))}
      {...props}
    />
  );
}

export function AccountSelect({ type, cashBank, ...props }: P & { type?: string | string[]; cashBank?: boolean }) {
  const { rows, isLoading } = useOptions<{ id: number; code: string; name: string; type: string; is_cash_bank: number }>('/accounts', {
    cash_bank: cashBank === undefined ? undefined : cashBank ? 1 : 0,
  });
  const types = type === undefined ? null : Array.isArray(type) ? type : [type];
  return (
    <Select
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Account"
      options={rows.filter((a) => !types || types.includes(a.type)).map((a) => ({ value: a.id, label: `${a.code} ${a.name}` }))}
      {...props}
    />
  );
}

export function GstRateSelect(props: P) {
  const { rows } = useOptions<{ id: number; name: string; rate: string }>('/tax-rates');
  const rates = Array.from(new Set(['18.00', ...rows.map((r) => r.rate)]));
  return <Select options={rates.map((r) => ({ value: r, label: `${Number(r)}%` }))} {...props} />;
}
