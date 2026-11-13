import { useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Alert, Button, Card, Col, DatePicker, Form, Input, Row, Select } from 'antd';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useGet, useList, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import { LineItems, linesPayload, type LineValue } from '../../components/LineItems';
import PageHeader from '../../components/PageHeader';
import { StateSelect, TenantSelect } from '../../components/Selects';
import { toApiDate } from '../../utils/date';
import { STATES } from '../../utils/money';
import type { AgreementDetail } from '../agreements/AgreementViewPage';

const DEFAULT_LINE: LineValue = { description: '', qty: 1, gst_rate: '18.00', sac: '997212' };

export default function InvoiceFormPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { company } = useAuth();
  const [form] = Form.useForm();
  const tenantId: number | undefined = Form.useWatch('tenant_id', form);
  const agreementId: number | undefined = Form.useWatch('agreement_id', form);
  const posOverride: string | undefined = Form.useWatch('place_of_supply', form);

  const tenant = useGet<{ id: number; state_code: string | null; name: string }>(tenantId ? `/tenants/${tenantId}` : null);
  const agreements = useList<{ id: number; unit_code: string; warehouse_name: string }>('/agreements', { tenant_id: tenantId, status: 'active', per_page: 50 }, !!tenantId);
  const agreement = useGet<AgreementDetail>(agreementId ? `/agreements/${agreementId}` : null);
  const presetAgreement = params.get('agreement');

  useEffect(() => {
    if (presetAgreement) form.setFieldValue('agreement_id', Number(presetAgreement));
    const t = params.get('tenant');
    if (t) form.setFieldValue('tenant_id', Number(t));
  }, [presetAgreement, params, form]);

  useEffect(() => {
    const a = agreement.data;
    if (!a) return;
    const month = dayjs().format('MMM YYYY');
    form.setFieldsValue({
      tenant_id: a.tenant_id,
      period: dayjs(),
      due_date: dayjs().add(a.due_days, 'day'),
      lines: [
        { description: `Warehouse rent ${a.warehouse_name} / ${a.unit_code} for ${month}`, qty: 1, rate: Number(a.current_rent), gst_rate: a.gst_rate, sac: a.sac },
        ...a.charges.map((c) => ({ description: `${c.description} for ${month}`, qty: 1, rate: Number(c.amount), gst_rate: c.gst_rate, sac: c.sac })),
      ],
    });
  }, [agreement.data, form]);

  const pos = useMemo(() => {
    if (posOverride) return posOverride;
    if (agreement.data?.property_state) return agreement.data.property_state;
    return tenant.data?.state_code ?? company?.state_code ?? '';
  }, [posOverride, agreement.data, tenant.data, company]);
  const interState = !!pos && pos !== company?.state_code;

  const save = useSave(
    (v: Record<string, any>) =>
      api.post<{ id: number }>('/invoices', {
        tenant_id: v.tenant_id,
        agreement_id: v.agreement_id ?? null,
        invoice_date: toApiDate(v.invoice_date),
        due_date: toApiDate(v.due_date) ?? null,
        period: v.agreement_id && v.period ? v.period.format('YYYY-MM') : null,
        place_of_supply: v.place_of_supply ?? null,
        notes: v.notes,
        lines: linesPayload(v.lines),
      }),
    { form, invalidate: ['/invoices', '/agreements'], success: 'Bill created', onSuccess: (r) => navigate(`/invoices/${r.id}`, { replace: true }) },
  );

  return (
    <div className="page">
      <PageHeader title="New bill" back />
      <Form form={form} layout="vertical" onFinish={save.mutate} initialValues={{ invoice_date: dayjs(), lines: [DEFAULT_LINE] }}>
        <Card size="small" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={24} md={10}>
              <Form.Item name="tenant_id" label="Tenant" rules={[{ required: true }]}>
                <TenantSelect onChange={() => form.setFieldsValue({ agreement_id: undefined, place_of_supply: undefined })} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="agreement_id" label="Agreement (optional)">
                <Select
                  allowClear
                  disabled={!tenantId}
                  placeholder="Not linked"
                  options={(agreements.data?.items ?? []).map((a) => ({ value: a.id, label: `${a.warehouse_name} / ${a.unit_code}` }))}
                />
              </Form.Item>
            </Col>
            {agreementId && (
              <Col xs={12} md={6}>
                <Form.Item name="period" label="Rent month" tooltip="Each agreement can be billed once per month">
                  <DatePicker picker="month" format="MMM YYYY" style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            )}
            <Col xs={12} md={6}>
              <Form.Item name="invoice_date" label="Bill date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="due_date" label="Due date"><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="place_of_supply" label="Place of supply" tooltip="For renting property this is the state where the warehouse is. Leave empty for automatic.">
                <StateSelect allowClear placeholder={pos ? `Auto: ${STATES[pos] ?? pos}` : 'Auto'} />
              </Form.Item>
            </Col>
          </Row>
          {tenantId && (
            <Alert type={interState ? 'warning' : 'info'} showIcon message={interState ? 'Inter-state supply: IGST will be charged' : 'Same state: CGST + SGST will be charged'} />
          )}
        </Card>

        <LineItems form={form} interState={interState} defaultLine={DEFAULT_LINE} />

        <Form.Item name="notes" label="Notes" style={{ marginTop: 12 }}><Input.TextArea rows={2} /></Form.Item>
        <Button type="primary" htmlType="submit" loading={save.isPending} block size="large">Save bill</Button>
      </Form>
    </div>
  );
}
