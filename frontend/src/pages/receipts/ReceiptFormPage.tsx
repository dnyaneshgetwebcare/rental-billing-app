import { useCallback, useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Button, Card, Col, DatePicker, Form, Input, InputNumber, Row, Segmented, Switch, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useList, useOptions, useSave } from '../../api/hooks';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { AccountSelect, TenantSelect } from '../../components/Selects';
import { fmtDate } from '../../utils/date';
import { formatMoney, fromPaise, toPaise } from '../../utils/money';

export const MODES = [
  { label: 'UPI', value: 'upi' },
  { label: 'Bank', value: 'bank' },
  { label: 'Cash', value: 'cash' },
  { label: 'Cheque', value: 'cheque' },
];

interface OpenInvoice {
  id: number;
  number: string;
  invoice_date: string;
  total: string;
  balance: string;
}

export function useCashBankDefault() {
  const { rows } = useOptions<{ id: number; system_key: string | null }>('/accounts', { cash_bank: 1 });
  return useCallback(
    (mode: string) => rows.find((a) => a.system_key === (mode === 'cash' ? 'CASH' : 'BANK'))?.id ?? rows[0]?.id,
    [rows],
  );
}

export default function ReceiptFormPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [form] = Form.useForm();
  const tenantId: number | undefined = Form.useWatch('tenant_id', form);
  const mode: string = Form.useWatch('mode', form) ?? 'upi';
  const manual: boolean = Form.useWatch('manual', form) ?? false;
  const amount = Form.useWatch('amount', form);
  const alloc: Record<string, number | null> = Form.useWatch('alloc', form) ?? {};
  const defaultAccount = useCashBankDefault();
  const presetInvoice = params.get('invoice');

  const open = useList<OpenInvoice>('/invoices', { tenant_id: tenantId, status: 'outstanding', per_page: 100 }, !!tenantId);
  const invoices = useMemo(() => [...(open.data?.items ?? [])].sort((a, b) => a.invoice_date.localeCompare(b.invoice_date) || a.id - b.id), [open.data]);
  const totalDue = invoices.reduce((s, i) => s + toPaise(i.balance), 0);

  useEffect(() => {
    const t = params.get('tenant');
    if (t) form.setFieldValue('tenant_id', Number(t));
  }, [params, form]);

  useEffect(() => {
    if (!presetInvoice || !invoices.length) return;
    const inv = invoices.find((i) => i.id === Number(presetInvoice));
    if (inv && form.getFieldValue('amount') === undefined) {
      form.setFieldsValue({ amount: Number(inv.balance), manual: true, alloc: { [inv.id]: Number(inv.balance) } });
    }
  }, [presetInvoice, invoices, form]);

  useEffect(() => {
    const id = defaultAccount(mode);
    if (id && !form.isFieldTouched('account_id')) form.setFieldValue('account_id', id);
  }, [mode, defaultAccount, form]);

  const allocated = Object.values(alloc).reduce<number>((s, v) => s + toPaise(v ?? 0), 0);
  const fifo = useMemo(() => {
    let left = toPaise(amount);
    return Object.fromEntries(invoices.map((i) => {
      const take = Math.max(0, Math.min(left, toPaise(i.balance)));
      left -= take;
      return [i.id, take];
    }));
  }, [amount, invoices]);

  const save = useSave(
    (v: Record<string, any>) =>
      api.post<{ id: number }>('/receipts', {
        tenant_id: v.tenant_id,
        receipt_date: v.receipt_date.format('YYYY-MM-DD'),
        amount: String(v.amount),
        mode: v.mode,
        account_id: v.account_id,
        reference: v.reference,
        notes: v.notes,
        allocations: v.manual
          ? Object.entries(v.alloc ?? {})
              .filter(([, a]) => a)
              .map(([invoiceId, a]) => ({ invoice_id: Number(invoiceId), amount: String(a) }))
          : [],
      }),
    { form, invalidate: ['/receipts', '/invoices'], success: 'Payment recorded', onSuccess: (r) => navigate(`/receipts/${r.id}`, { replace: true }) },
  );

  return (
    <div className="page">
      <PageHeader title="Receive payment" back />
      <Form form={form} layout="vertical" onFinish={save.mutate} initialValues={{ receipt_date: dayjs(), mode: 'upi', manual: false }}>
        <Card size="small" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={24} md={12}>
              <Form.Item name="tenant_id" label="Tenant" rules={[{ required: true }]}>
                <TenantSelect onChange={() => form.setFieldsValue({ alloc: {} })} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="amount" label="Amount received" rules={[{ required: true }]}>
                <InputNumber min={0.01} style={{ width: '100%' }} inputMode="decimal" size="large" />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="receipt_date" label="Date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="mode" label="Mode"><Segmented block options={MODES} /></Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="account_id" label="Received into" rules={[{ required: true }]}><AccountSelect cashBank /></Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="reference" label="UTR / cheque no."><Input /></Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="notes" label="Notes"><Input /></Form.Item>
            </Col>
          </Row>
        </Card>

        {tenantId && (
          <Card
            size="small"
            title={<>Due bills · total <Money value={fromPaise(totalDue)} type="danger" /></>}
            extra={<Form.Item name="manual" valuePropName="checked" noStyle><Switch checkedChildren="Choose bills" unCheckedChildren="Oldest first" /></Form.Item>}
            style={{ marginBottom: 12 }}
          >
            <Table
              size="small"
              rowKey="id"
              pagination={false}
              loading={open.isFetching}
              dataSource={invoices}
              locale={{ emptyText: 'No due bills. The amount will be kept as advance.' }}
              columns={[
                { title: 'Bill', dataIndex: 'number' },
                { title: 'Date', dataIndex: 'invoice_date', render: fmtDate },
                { title: 'Balance', dataIndex: 'balance', align: 'right', render: (v) => <Money value={v} /> },
                {
                  title: 'Apply',
                  align: 'right',
                  width: 140,
                  render: (_, r) =>
                    manual ? (
                      <Form.Item name={['alloc', String(r.id)]} noStyle>
                        <InputNumber min={0} max={Number(r.balance)} style={{ width: 120 }} />
                      </Form.Item>
                    ) : (
                      <Money value={fromPaise(fifo[r.id] ?? 0)} />
                    ),
                },
              ]}
            />
            {manual && (
              <Typography.Text type={allocated > toPaise(amount) ? 'danger' : 'secondary'} style={{ display: 'block', marginTop: 8 }}>
                Applied ₹ {formatMoney(fromPaise(allocated))} of ₹ {formatMoney(amount ?? 0)}; the rest is kept as advance.
              </Typography.Text>
            )}
          </Card>
        )}

        <Button type="primary" htmlType="submit" loading={save.isPending} block size="large">Save payment</Button>
      </Form>
    </div>
  );
}
