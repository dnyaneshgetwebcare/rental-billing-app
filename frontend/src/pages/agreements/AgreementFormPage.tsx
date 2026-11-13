import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Button, Card, Col, DatePicker, Form, Input, InputNumber, Row, Spin, Switch, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import PageHeader from '../../components/PageHeader';
import { GstRateSelect, TenantSelect, UnitSelect } from '../../components/Selects';
import { fromApiDate, toApiDate } from '../../utils/date';
import type { AgreementDetail } from './AgreementViewPage';

const str = (v: unknown) => (v === undefined || v === null || v === '' ? undefined : String(v));

export default function AgreementFormPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form] = Form.useForm();
  const existing = useGet<AgreementDetail>(id ? `/agreements/${id}` : null);

  useEffect(() => {
    const a = existing.data;
    if (!a) return;
    form.setFieldsValue({
      ...a,
      start_date: fromApiDate(a.start_date),
      end_date: fromApiDate(a.end_date),
      auto_bill: !!a.auto_bill,
      rent_amount: Number(a.rent_amount),
      deposit_amount: Number(a.deposit_amount),
      escalation_pct: Number(a.escalation_pct),
      charges: a.charges.map((c) => ({ ...c, amount: Number(c.amount) })),
    });
  }, [existing.data, form]);

  const save = useSave(
    (v: Record<string, any>) => {
      const body = {
        ...v,
        start_date: toApiDate(v.start_date),
        end_date: toApiDate(v.end_date) ?? null,
        rent_amount: str(v.rent_amount),
        deposit_amount: str(v.deposit_amount) ?? '0',
        escalation_pct: str(v.escalation_pct) ?? '0',
        charges: (v.charges ?? []).map((c: any) => ({ ...c, amount: str(c.amount) })),
      };
      return id ? api.put<AgreementDetail>(`/agreements/${id}`, body) : api.post<AgreementDetail>('/agreements', body);
    },
    { form, invalidate: ['/agreements', '/units'], success: 'Agreement saved', onSuccess: (a) => navigate(`/agreements/${a.id}`, { replace: true }) },
  );

  if (id && existing.isLoading) return <div className="page"><Spin /></div>;

  return (
    <div className="page">
      <PageHeader title={id ? 'Edit agreement' : 'New agreement'} back />
      <Form
        form={form}
        layout="vertical"
        onFinish={save.mutate}
        initialValues={{ gst_rate: '18.00', sac: '997212', billing_day: 1, due_days: 7, escalation_pct: 0, escalation_months: 12, auto_bill: true, charges: [] }}
      >
        <Card size="small" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={24} md={12}>
              <Form.Item name="tenant_id" label="Tenant" rules={[{ required: true }]}><TenantSelect disabled={!!id} /></Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="unit_id" label="Unit" rules={[{ required: true }]}><UnitSelect onlyFree disabled={!!id} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="start_date" label="Start date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="end_date" label="End date"><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="agreement_no" label="Agreement no."><Input /></Form.Item>
            </Col>
          </Row>
        </Card>

        <Card size="small" title="Rent & billing" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={12} md={6}>
              <Form.Item name="rent_amount" label="Monthly rent (before GST)" rules={[{ required: true }]}>
                <InputNumber min={0} style={{ width: '100%' }} inputMode="decimal" />
              </Form.Item>
            </Col>
            <Col xs={6} md={3}><Form.Item name="gst_rate" label="GST"><GstRateSelect /></Form.Item></Col>
            <Col xs={6} md={3}><Form.Item name="sac" label="SAC"><Input /></Form.Item></Col>
            <Col xs={12} md={6}>
              <Form.Item name="deposit_amount" label="Security deposit"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="auto_bill" label="Auto bill every month" valuePropName="checked"><Switch /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="billing_day" label="Bill on day" tooltip="1 to 28"><InputNumber min={1} max={28} style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="due_days" label="Due after (days)"><InputNumber min={0} max={90} style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="escalation_pct" label="Increase %"><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="escalation_months" label="Every (months)"><InputNumber min={1} max={120} style={{ width: '100%' }} /></Form.Item>
            </Col>
          </Row>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            Rent increases by the given percent every N months from the start date, compounded.
          </Typography.Text>
        </Card>

        <Card size="small" title="Other monthly charges" style={{ marginBottom: 12 }}>
          <Form.List name="charges">
            {(fields, { add, remove }) => (
              <>
                {fields.map((f) => (
                  <Row gutter={8} key={f.key}>
                    <Col xs={24} md={12}>
                      <Form.Item name={[f.name, 'description']} rules={[{ required: true, message: 'Required' }]}><Input placeholder="e.g. Maintenance" /></Form.Item>
                    </Col>
                    <Col xs={10} md={5}>
                      <Form.Item name={[f.name, 'amount']} rules={[{ required: true, message: 'Required' }]}><InputNumber min={0} placeholder="Amount" style={{ width: '100%' }} /></Form.Item>
                    </Col>
                    <Col xs={10} md={5}><Form.Item name={[f.name, 'gst_rate']} initialValue="18.00"><GstRateSelect /></Form.Item></Col>
                    <Col xs={4} md={2}><Button type="text" danger icon={<DeleteOutlined />} onClick={() => remove(f.name)} /></Col>
                  </Row>
                ))}
                <Button icon={<PlusOutlined />} onClick={() => add({ gst_rate: '18.00' })}>Add charge</Button>
              </>
            )}
          </Form.List>
        </Card>

        <Form.Item name="notes" label="Notes"><Input.TextArea rows={2} /></Form.Item>
        <Button type="primary" htmlType="submit" loading={save.isPending} block size="large">Save agreement</Button>
      </Form>
    </div>
  );
}
