import { useEffect, useState } from 'react';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Segmented, Select, Space, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useList, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import DocList from '../../components/DocList';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { AccountSelect, VendorSelect } from '../../components/Selects';
import { fmtDate } from '../../utils/date';
import { MODES, useCashBankDefault } from '../receipts/ReceiptFormPage';

interface Row {
  id: number;
  payment_date: string;
  vendor_name: string;
  bill_no: string | null;
  amount: string;
  mode: string;
  reference: string | null;
  account_name: string;
  status: string;
}

export function PayVendorModal({ open, onClose, vendorId, purchaseId, amount }: { open: boolean; onClose: () => void; vendorId?: number; purchaseId?: number; amount?: string }) {
  const [form] = Form.useForm();
  const mode: string = Form.useWatch('mode', form) ?? 'bank';
  const vendor: number | undefined = Form.useWatch('vendor_id', form);
  const defaultAccount = useCashBankDefault();
  const bills = useList<{ id: number; bill_no: string; balance: string }>('/purchases', { vendor_id: vendor, status: 'outstanding', per_page: 100 }, open && !!vendor);

  useEffect(() => {
    if (!open) return;
    form.resetFields();
    form.setFieldsValue({ vendor_id: vendorId, purchase_id: purchaseId, amount: amount ? Number(amount) : undefined, payment_date: dayjs(), mode: 'bank' });
  }, [open, vendorId, purchaseId, amount, form]);

  useEffect(() => {
    const id = defaultAccount(mode);
    if (open && id && !form.isFieldTouched('account_id')) form.setFieldValue('account_id', id);
  }, [open, mode, defaultAccount, form]);

  const save = useSave(
    (v: Record<string, any>) =>
      api.post('/vendor-payments', {
        ...v,
        purchase_id: v.purchase_id ?? null,
        amount: String(v.amount),
        payment_date: v.payment_date.format('YYYY-MM-DD'),
      }),
    { form, invalidate: ['/vendor-payments', '/purchases'], success: 'Payment saved', onSuccess: onClose },
  );

  return (
    <Modal title="Pay vendor" open={open} onCancel={onClose} onOk={() => form.submit()} confirmLoading={save.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={save.mutate}>
        <Form.Item name="vendor_id" label="Vendor" rules={[{ required: true }]}><VendorSelect disabled={!!vendorId} onChange={() => form.setFieldValue('purchase_id', undefined)} /></Form.Item>
        <Form.Item name="purchase_id" label="Against bill" tooltip="Leave empty for an advance / on-account payment">
          <Select
            allowClear
            disabled={!!purchaseId}
            placeholder="On account"
            options={(bills.data?.items ?? []).map((b) => ({ value: b.id, label: `${b.bill_no} · balance ${b.balance}` }))}
            onChange={(id) => {
              const b = bills.data?.items.find((x) => x.id === id);
              if (b) form.setFieldValue('amount', Number(b.balance));
            }}
          />
        </Form.Item>
        <Space wrap>
          <Form.Item name="amount" label="Amount" rules={[{ required: true }]}><InputNumber min={0.01} style={{ width: 160 }} /></Form.Item>
          <Form.Item name="payment_date" label="Date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" /></Form.Item>
        </Space>
        <Form.Item name="mode" label="Mode"><Segmented options={MODES} /></Form.Item>
        <Form.Item name="account_id" label="Paid from" rules={[{ required: true }]}><AccountSelect cashBank /></Form.Item>
        <Form.Item name="reference" label="UTR / cheque no."><Input /></Form.Item>
      </Form>
    </Modal>
  );
}

export default function VendorPaymentsPage() {
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [vendor, setVendor] = useState<number>();
  const [paying, setPaying] = useState(false);
  const cancel = useSave((id: number) => api.post(`/vendor-payments/${id}/cancel`), { invalidate: ['/vendor-payments', '/purchases'], success: 'Payment cancelled' });

  return (
    <div className="page">
      <PageHeader title="Vendor payments" extra={can('accountant') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setPaying(true)}>Pay vendor</Button>} />
      <Space wrap style={{ marginBottom: 12 }}>
        <VendorSelect allowClear value={vendor} onChange={(v) => { setVendor(v); setPage(1); }} style={{ width: 220 }} />
      </Space>
      <DocList<Row>
        path="/vendor-payments"
        query={{ vendor_id: vendor }}
        page={page}
        onPage={setPage}
        columns={[
          { title: 'Date', dataIndex: 'payment_date', render: fmtDate, width: 120 },
          { title: 'Vendor', dataIndex: 'vendor_name' },
          { title: 'Bill', dataIndex: 'bill_no', render: (b) => b ?? <Tag>on account</Tag> },
          { title: 'From', dataIndex: 'account_name' },
          { title: 'Mode', dataIndex: 'mode', render: (m, r) => `${m.toUpperCase()}${r.reference ? ' · ' + r.reference : ''}` },
          { title: 'Amount', dataIndex: 'amount', align: 'right', render: (v) => <Money value={v} /> },
          {
            title: '',
            width: 110,
            render: (_, r) =>
              r.status === 'cancelled' ? <Tag>cancelled</Tag> : can('accountant') && (
                <Popconfirm title="Cancel this payment?" onConfirm={() => cancel.mutate(r.id)}><Button size="small" danger>Cancel</Button></Popconfirm>
              ),
          },
        ]}
        mobile={(r) => ({
          title: r.vendor_name,
          subtitle: `${fmtDate(r.payment_date)} · ${r.bill_no ?? 'on account'} · ${r.mode.toUpperCase()}`,
          right: <><Money value={r.amount} strong />{r.status === 'cancelled' && <div><Tag>cancelled</Tag></div>}</>,
        })}
      />
      <PayVendorModal open={paying} onClose={() => setPaying(false)} />
    </div>
  );
}
