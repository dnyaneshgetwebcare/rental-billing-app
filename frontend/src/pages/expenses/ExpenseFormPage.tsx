import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Card, Col, DatePicker, Form, Input, InputNumber, Row, Segmented, Space, Switch, Tag, Typography, message } from 'antd';
import { CameraOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useSave } from '../../api/hooks';
import { GstTotalsView } from '../../components/LineItems';
import PageHeader from '../../components/PageHeader';
import { AccountSelect, GstRateSelect, VendorSelect } from '../../components/Selects';
import { shrinkImage } from '../../utils/files';
import { gstTotals } from '../../utils/money';
import { MODES, useCashBankDefault } from '../receipts/ReceiptFormPage';

/** Quick expense paid on the spot, with an optional photo of the bill. */
export default function ExpenseFormPage() {
  const navigate = useNavigate();
  const [form] = Form.useForm();
  const [files, setFiles] = useState<File[]>([]);
  const input = useRef<HTMLInputElement>(null);
  const mode: string = Form.useWatch('payment_mode', form) ?? 'cash';
  const amount = Form.useWatch('amount', form);
  const gstRate = Form.useWatch('gst_rate', form);
  const interState: boolean = Form.useWatch('is_inter_state', form) ?? false;
  const defaultAccount = useCashBankDefault();

  useEffect(() => {
    const id = defaultAccount(mode);
    if (id && !form.isFieldTouched('paid_from_account_id')) form.setFieldValue('paid_from_account_id', id);
  }, [mode, defaultAccount, form]);

  const save = useSave(
    async (v: Record<string, any>) => {
      const expense = await api.post<{ id: number }>('/expenses', {
        ...v,
        expense_date: v.expense_date.format('YYYY-MM-DD'),
        amount: String(v.amount),
        gst_rate: String(v.gst_rate ?? 0),
      });
      try {
        for (const f of files) {
          const body = new FormData();
          body.append('owner_type', 'expense');
          body.append('owner_id', String(expense.id));
          body.append('file', await shrinkImage(f));
          await api.post('/attachments', body);
        }
      } catch (e) {
        message.warning(`Expense saved, but the photo could not be uploaded (${e instanceof Error ? e.message : 'error'}). Open the expense to add it again.`, 8);
      }
      return expense;
    },
    { form, invalidate: ['/expenses'], success: 'Expense saved', onSuccess: () => navigate('/expenses', { replace: true }) },
  );

  const totals = gstTotals([{ qty: 1, rate: amount, gst_rate: gstRate }], interState);

  return (
    <div className="page">
      <PageHeader title="New expense" back />
      <Form form={form} layout="vertical" onFinish={save.mutate} initialValues={{ expense_date: dayjs(), payment_mode: 'cash', gst_rate: '0.00', is_inter_state: false }}>
        <Card size="small" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={24} md={12}><Form.Item name="account_id" label="Expense head" rules={[{ required: true }]}><AccountSelect type="expense" cashBank={false} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="amount" label="Amount (before GST)" rules={[{ required: true }]}><InputNumber min={0.01} style={{ width: '100%' }} inputMode="decimal" size="large" /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="expense_date" label="Date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="description" label="Description" rules={[{ required: true }]}><Input placeholder="e.g. Diesel for genset" /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="gst_rate" label="GST on bill"><GstRateSelect /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="is_inter_state" label="Other-state supplier (IGST)" valuePropName="checked"><Switch /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="payment_mode" label="Paid by"><Segmented block options={MODES} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="paid_from_account_id" label="Paid from" rules={[{ required: true }]}><AccountSelect cashBank /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="vendor_id" label="Vendor (optional)"><VendorSelect allowClear /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="reference" label="Bill / UTR no."><Input /></Form.Item></Col>
          </Row>
          <input
            ref={input}
            type="file"
            accept="image/*,application/pdf"
            capture="environment"
            multiple
            hidden
            onChange={(e) => {
              setFiles((f) => [...f, ...Array.from(e.target.files ?? [])]);
              e.target.value = '';
            }}
          />
          <Space wrap>
            <Button icon={<CameraOutlined />} onClick={() => input.current?.click()}>Photo of bill</Button>
            {files.map((f, i) => (
              <Tag key={i} closable onClose={() => setFiles((all) => all.filter((_, j) => j !== i))}>{f.name}</Tag>
            ))}
          </Space>
        </Card>
        {Number(gstRate) > 0 && <GstTotalsView totals={totals} interState={interState} />}
        <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8 }}>
          Bought on credit? Enter it under Purchases instead and pay it later.
        </Typography.Paragraph>
        <Button type="primary" htmlType="submit" loading={save.isPending} block size="large">Save expense</Button>
      </Form>
    </div>
  );
}
