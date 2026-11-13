import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Card, Col, Form, Input, InputNumber, Row, Select, Spin } from 'antd';
import { api } from '../api/client';
import { showError, useGet, useSave } from '../api/hooks';
import { useAuth } from '../auth/AuthContext';
import { stateFromGstin, stateOptions } from '../utils/money';
import { useI18n } from '../i18n';

/** Create a company (mode "new") or edit the current company's settings. */
export default function CompanyFormPage({ mode }: { mode: 'new' | 'edit' }) {
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const { reload, selectCompany, can } = useAuth();
  const { t } = useI18n();
  const current = useGet<Record<string, unknown>>(mode === 'edit' ? '/company' : null);

  useEffect(() => {
    if (current.data) form.setFieldsValue(current.data);
  }, [current.data, form]);

  const save = useSave(
    (v: Record<string, unknown>) => (mode === 'new' ? api.post<{ id: number }>('/companies', v) : api.put<{ id: number }>('/company', v)),
    {
      form,
      success: t('company.saved'),
      invalidate: ['/company'],
      onSuccess: async (c) => {
        await reload();
        if (mode === 'new') {
          selectCompany(c.id);
          navigate('/', { replace: true });
        }
      },
    },
  );

  if (mode === 'edit' && current.isLoading) return <Spin style={{ margin: 48 }} />;
  const readOnly = mode === 'edit' && !can();

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <Card title={mode === 'new' ? t('company.new') : t('company.settings')}>
        <Form
          form={form}
          layout="vertical"
          disabled={readOnly}
          initialValues={{ fy_start_month: 4, invoice_print_language: 'en' }}
          onValuesChange={(changed) => {
            if (changed.gstin) {
              const s = stateFromGstin(String(changed.gstin).toUpperCase());
              if (s) form.setFieldValue('state_code', s);
            }
          }}
          onFinish={(v) => save.mutate(v)}
          onFinishFailed={() => showError(new Error(t('company.fix')))}
        >
          <Row gutter={12}>
            <Col xs={24} md={12}><Form.Item name="name" label={t('company.displayName')} rules={[{ required: true }]}><Input /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="legal_name" label={t('company.legalName')}><Input /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="gstin" label={t('company.gstin')} normalize={(v) => v?.toUpperCase()}><Input maxLength={15} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="state_code" label={t('company.state')} rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={stateOptions} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="pan" label={t('company.pan')} normalize={(v) => v?.toUpperCase()}><Input maxLength={10} /></Form.Item></Col>
            <Col xs={12} md={8}>
              <Form.Item name="invoice_prefix" label={t('company.prefix')} rules={[{ required: true, pattern: /^[A-Za-z0-9]{1,5}$/, message: '1-5 letters/digits' }]} normalize={(v) => v?.toUpperCase()} extra={t('company.prefixHint')}>
                <Input maxLength={5} />
              </Form.Item>
            </Col>
            <Col xs={12} md={8}><Form.Item name="fy_start_month" label={t('company.fy')}><InputNumber min={1} max={12} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={8}>
              <Form.Item name="invoice_print_language" label={t('company.printLanguage')} extra={t('company.printHint')}>
                <Select options={[{ value: 'en', label: t('company.printEn') }, { value: 'en_mr', label: t('company.printBoth') }]} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}><Form.Item name="phone" label={t('company.phone')}><Input inputMode="tel" /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="email" label={t('company.email')}><Input inputMode="email" /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="city" label={t('company.city')}><Input /></Form.Item></Col>
            <Col xs={24} md={16}><Form.Item name="address" label={t('company.address')}><Input.TextArea rows={2} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="pincode" label={t('company.pin')}><Input inputMode="numeric" /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="bank_details" label={t('company.bank')}><Input.TextArea rows={3} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="invoice_terms" label={t('company.terms')}><Input.TextArea rows={3} /></Form.Item></Col>
          </Row>
          {!readOnly && <Button type="primary" htmlType="submit" loading={save.isPending}>{t('company.save')}</Button>}
        </Form>
      </Card>
    </div>
  );
}
