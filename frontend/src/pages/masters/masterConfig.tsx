import type { ComponentType, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Button, Col, Form, Input, InputNumber, Row as GridRow, Select, Space, Switch, Tag, type FormInstance } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { StateSelect, WarehouseSelect } from '../../components/Selects';
import { STATES, stateFromGstin } from '../../utils/money';
import type { Role } from '../../auth/AuthContext';

export type Row = Record<string, any> & { id?: number };
export type MasterResource = 'tenants' | 'vendors' | 'warehouses' | 'units' | 'tax-rates' | 'accounts';

interface FieldsProps {
  form: FormInstance;
  editing: Row | 'new' | null;
}

interface MasterConfig {
  title: string;
  singular: string;
  writeRoles: Role[];
  perPage?: number;
  columns: ColumnsType<Row>;
  mobileTitle: (r: Row) => ReactNode;
  mobileSubtitle?: (r: Row) => ReactNode;
  Fields: ComponentType<FieldsProps>;
  defaults?: Row;
  toPayload?: (v: Row) => Row;
  onValuesChange?: (changed: Row, form: FormInstance) => void;
  rowActions?: (r: Row) => ReactNode;
}

const Active = () => (
  <Form.Item name="is_active" label="Active" valuePropName="checked">
    <Switch />
  </Form.Item>
);

function PartyFields() {
  return (
    <GridRow gutter={12}>
      <Col span={24}><Form.Item name="name" label="Name" rules={[{ required: true }]}><Input /></Form.Item></Col>
      <Col xs={24} sm={12}><Form.Item name="gstin" label="GSTIN" normalize={(v) => v?.toUpperCase()}><Input maxLength={15} placeholder="Leave empty if unregistered" /></Form.Item></Col>
      <Col xs={24} sm={12}><Form.Item name="state_code" label="State" rules={[{ required: true }]}><StateSelect /></Form.Item></Col>
      <Col xs={24} sm={12}><Form.Item name="contact_person" label="Contact person"><Input /></Form.Item></Col>
      <Col xs={24} sm={12}><Form.Item name="pan" label="PAN" normalize={(v) => v?.toUpperCase()}><Input maxLength={10} /></Form.Item></Col>
      <Col xs={24} sm={12}><Form.Item name="phone" label="Phone"><Input inputMode="tel" /></Form.Item></Col>
      <Col xs={24} sm={12}><Form.Item name="whatsapp" label="WhatsApp number" extra="Used for sharing bills"><Input inputMode="tel" /></Form.Item></Col>
      <Col span={24}><Form.Item name="email" label="Email"><Input inputMode="email" /></Form.Item></Col>
      <Col span={24}><Form.Item name="address" label="Address"><Input.TextArea rows={2} /></Form.Item></Col>
      <Col span={24}><Active /></Col>
    </GridRow>
  );
}

const gstinToState = (changed: Row, form: FormInstance) => {
  if (changed.gstin) {
    const s = stateFromGstin(String(changed.gstin));
    if (s && STATES[s]) form.setFieldValue('state_code', s);
  }
};

const partyColumns: ColumnsType<Row> = [
  { title: 'Name', dataIndex: 'name' },
  { title: 'GSTIN', dataIndex: 'gstin', render: (v) => v ?? <Tag>Unregistered</Tag> },
  { title: 'State', dataIndex: 'state_code', render: (v) => `${v} ${STATES[v] ?? ''}` },
  { title: 'Phone', dataIndex: 'phone' },
];

export const MASTERS: Record<MasterResource, MasterConfig> = {
  tenants: {
    title: 'Tenants',
    singular: 'tenant',
    writeRoles: ['accountant', 'staff'],
    columns: partyColumns,
    mobileTitle: (r) => r.name,
    mobileSubtitle: (r) => [r.phone, r.gstin].filter(Boolean).join(' · '),
    Fields: PartyFields,
    onValuesChange: gstinToState,
    rowActions: (r) => (
      <Space size={4}>
        <Link to={`/invoices/new?tenant=${r.id}`}><Button size="small">New bill</Button></Link>
        <Link to={`/reports/party-ledger?party_type=tenant&party_id=${r.id}`}><Button size="small">Statement</Button></Link>
      </Space>
    ),
  },
  vendors: {
    title: 'Vendors',
    singular: 'vendor',
    writeRoles: ['accountant', 'staff'],
    columns: partyColumns,
    mobileTitle: (r) => r.name,
    mobileSubtitle: (r) => [r.phone, r.gstin].filter(Boolean).join(' · '),
    Fields: PartyFields,
    onValuesChange: gstinToState,
    rowActions: (r) => (
      <Link to={`/reports/party-ledger?party_type=vendor&party_id=${r.id}`}><Button size="small">Statement</Button></Link>
    ),
  },
  warehouses: {
    title: 'Warehouses',
    singular: 'warehouse',
    writeRoles: ['accountant'],
    columns: [
      { title: 'Name', dataIndex: 'name' },
      { title: 'City', dataIndex: 'city' },
      { title: 'State', dataIndex: 'state_code', render: (v) => (v ? `${v} ${STATES[v] ?? ''}` : '') },
    ],
    mobileTitle: (r) => r.name,
    mobileSubtitle: (r) => r.city,
    Fields: () => (
      <>
        <Form.Item name="name" label="Name" rules={[{ required: true }]}><Input /></Form.Item>
        <Form.Item name="address" label="Address"><Input.TextArea rows={2} /></Form.Item>
        <Form.Item name="city" label="City"><Input /></Form.Item>
        <Form.Item name="state_code" label="State (where the property is)" extra="GST place of supply for rent is the property's state" rules={[{ required: true }]}>
          <StateSelect />
        </Form.Item>
        <Active />
      </>
    ),
  },
  units: {
    title: 'Units',
    singular: 'unit',
    writeRoles: ['accountant'],
    perPage: 50,
    columns: [
      { title: 'Warehouse', dataIndex: 'warehouse_name' },
      { title: 'Unit', dataIndex: 'code' },
      { title: 'Area (sq ft)', dataIndex: 'area_sqft', align: 'right' },
      { title: 'Description', dataIndex: 'description' },
      {
        title: 'Occupancy',
        dataIndex: 'active_agreement_id',
        render: (v) => (v ? <Link to={`/agreements/${v}`} onClick={(e) => e.stopPropagation()}><Tag color="blue">Let out</Tag></Link> : <Tag color="green">Vacant</Tag>),
      },
    ],
    mobileTitle: (r) => `${r.warehouse_name} / ${r.code}`,
    mobileSubtitle: (r) => `${r.area_sqft ? r.area_sqft + ' sq ft · ' : ''}${r.active_agreement_id ? 'Let out' : 'Vacant'}`,
    Fields: () => (
      <>
        <Form.Item name="warehouse_id" label="Warehouse" rules={[{ required: true }]}><WarehouseSelect /></Form.Item>
        <Form.Item name="code" label="Unit code / number" rules={[{ required: true }]}><Input /></Form.Item>
        <Form.Item name="area_sqft" label="Area (sq ft)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="description" label="Description"><Input /></Form.Item>
        <Active />
      </>
    ),
  },
  'tax-rates': {
    title: 'Tax rates',
    singular: 'tax rate',
    writeRoles: ['accountant'],
    columns: [
      { title: 'Name', dataIndex: 'name' },
      { title: 'Rate %', dataIndex: 'rate', align: 'right' },
      { title: 'SAC', dataIndex: 'sac' },
      { title: 'Default', dataIndex: 'is_default', render: (v) => (v ? <Tag color="blue">Default</Tag> : null) },
    ],
    mobileTitle: (r) => `${r.name} (${Number(r.rate)}%)`,
    mobileSubtitle: (r) => r.sac,
    Fields: () => (
      <>
        <Form.Item name="name" label="Name" rules={[{ required: true }]}><Input /></Form.Item>
        <Form.Item name="rate" label="GST rate %" rules={[{ required: true }]}>
          <Select options={['0', '5', '12', '18', '28'].map((v) => ({ value: v, label: `${v}%` }))} />
        </Form.Item>
        <Form.Item name="sac" label="SAC / HSN"><Input /></Form.Item>
        <Form.Item name="is_default" label="Default" valuePropName="checked"><Switch /></Form.Item>
        <Active />
      </>
    ),
  },
  accounts: {
    title: 'Chart of accounts',
    singular: 'account',
    writeRoles: ['accountant'],
    perPage: 200,
    columns: [
      { title: 'Code', dataIndex: 'code', width: 90 },
      { title: 'Name', dataIndex: 'name' },
      { title: 'Type', dataIndex: 'type', render: (v) => <Tag>{v}</Tag> },
      { title: '', dataIndex: 'system_key', render: (v, r) => <Space>{v && <Tag color="purple">system</Tag>}{r.is_cash_bank ? <Tag color="gold">cash/bank</Tag> : null}</Space> },
    ],
    mobileTitle: (r) => `${r.code} ${r.name}`,
    mobileSubtitle: (r) => r.type,
    defaults: { is_active: true, type: 'expense' },
    rowActions: (r) => (
      <Link to={`/reports/ledger?account_id=${r.id}`}><Button size="small">Ledger</Button></Link>
    ),
    Fields: ({ editing }) => {
      const system = editing !== 'new' && editing !== null && !!editing.system_key;
      return (
        <>
          <Form.Item name="code" label="Code" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="name" label="Name" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="type" label="Type" rules={[{ required: true }]} extra={system ? 'System account: type is fixed' : undefined}>
            <Select disabled={system} options={['asset', 'liability', 'equity', 'income', 'expense'].map((v) => ({ value: v, label: v }))} />
          </Form.Item>
          <Form.Item name="is_cash_bank" label="Cash or bank account" valuePropName="checked" extra="Shown when receiving or paying money"><Switch /></Form.Item>
          <Active />
        </>
      );
    },
  },
};
