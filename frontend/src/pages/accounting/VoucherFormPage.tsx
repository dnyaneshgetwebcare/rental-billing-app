import { useNavigate } from 'react-router-dom';
import { Alert, Button, Card, Col, DatePicker, Form, Input, InputNumber, Row, Select, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useSave } from '../../api/hooks';
import Money from '../../components/Money';
import PageHeader from '../../components/PageHeader';
import { AccountSelect, TenantSelect, VendorSelect } from '../../components/Selects';
import { fromPaise, toPaise } from '../../utils/money';

interface JournalLine {
  account_id?: number;
  debit?: number;
  credit?: number;
  party_type?: 'tenant' | 'vendor';
  party_id?: number;
  narration?: string;
}

/**
 * Manual journal: opening balances, security deposits, depreciation, corrections.
 */
export default function VoucherFormPage() {
  const navigate = useNavigate();
  const [form] = Form.useForm();
  const lines: JournalLine[] = Form.useWatch('lines', form) ?? [];
  const debit = lines.reduce((s, l) => s + toPaise(l?.debit ?? 0), 0);
  const credit = lines.reduce((s, l) => s + toPaise(l?.credit ?? 0), 0);

  const save = useSave(
    (v: { voucher_date: dayjs.Dayjs; narration: string; lines: JournalLine[] }) =>
      api.post<{ id: number }>('/vouchers', {
        voucher_date: v.voucher_date.format('YYYY-MM-DD'),
        narration: v.narration,
        lines: v.lines.map((l) => ({
          account_id: l.account_id,
          debit: String(l.debit ?? 0),
          credit: String(l.credit ?? 0),
          party_type: l.party_type ?? null,
          party_id: l.party_type ? l.party_id : null,
          narration: l.narration,
        })),
      }),
    { form, invalidate: ['/vouchers', '/reports'], success: 'Journal posted', onSuccess: (r) => navigate(`/vouchers/${r.id}`, { replace: true }) },
  );

  return (
    <div className="page">
      <PageHeader title="Journal entry" back />
      <Form form={form} layout="vertical" onFinish={save.mutate} initialValues={{ voucher_date: dayjs(), lines: [{}, {}] }}>
        <Card size="small" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={24} md={6}><Form.Item name="voucher_date" label="Date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={18}><Form.Item name="narration" label="Narration" rules={[{ required: true }]}><Input placeholder="e.g. Opening balance as on 1 April" /></Form.Item></Col>
          </Row>
        </Card>
        <Form.List name="lines">
          {(fields, { add, remove }) => (
            <>
              {fields.map((f) => (
                <Card key={f.key} size="small" style={{ marginBottom: 8 }} styles={{ body: { paddingBottom: 0 } }}>
                  <Row gutter={8}>
                    <Col xs={24} md={8}><Form.Item name={[f.name, 'account_id']} rules={[{ required: true, message: 'Account' }]}><AccountSelect /></Form.Item></Col>
                    <Col xs={12} md={4}>
                      <Form.Item name={[f.name, 'debit']}>
                        <InputNumber min={0} placeholder="Debit" style={{ width: '100%' }} onChange={(v) => v && form.setFieldValue(['lines', f.name, 'credit'], undefined)} />
                      </Form.Item>
                    </Col>
                    <Col xs={12} md={4}>
                      <Form.Item name={[f.name, 'credit']}>
                        <InputNumber min={0} placeholder="Credit" style={{ width: '100%' }} onChange={(v) => v && form.setFieldValue(['lines', f.name, 'debit'], undefined)} />
                      </Form.Item>
                    </Col>
                    <Col xs={10} md={3}>
                      <Form.Item name={[f.name, 'party_type']}>
                        <Select allowClear placeholder="Party" options={[{ value: 'tenant', label: 'Tenant' }, { value: 'vendor', label: 'Vendor' }]} />
                      </Form.Item>
                    </Col>
                    <Col xs={12} md={4}>
                      <Form.Item noStyle shouldUpdate>
                        {() => {
                          const pt = form.getFieldValue(['lines', f.name, 'party_type']);
                          if (!pt) return null;
                          return (
                            <Form.Item name={[f.name, 'party_id']} rules={[{ required: true, message: 'Choose' }]}>
                              {pt === 'tenant' ? <TenantSelect /> : <VendorSelect />}
                            </Form.Item>
                          );
                        }}
                      </Form.Item>
                    </Col>
                    <Col xs={2} md={1}>{fields.length > 2 && <Button type="text" danger icon={<DeleteOutlined />} onClick={() => remove(f.name)} />}</Col>
                  </Row>
                </Card>
              ))}
              <Button icon={<PlusOutlined />} onClick={() => add({})} style={{ marginBottom: 12 }}>Add line</Button>
            </>
          )}
        </Form.List>
        <Card size="small" style={{ marginBottom: 12 }}>
          <Typography.Text>Debit <Money value={fromPaise(debit)} strong /> · Credit <Money value={fromPaise(credit)} strong /></Typography.Text>
          {debit !== credit && <Alert type="error" showIcon style={{ marginTop: 8 }} message={`Difference ${fromPaise(debit - credit)}: debits and credits must be equal.`} />}
          <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8, marginBottom: 0 }}>
            Set the party on Debtors / Creditors / Deposit lines so the tenant or vendor statement shows the entry.
          </Typography.Paragraph>
        </Card>
        <Button type="primary" htmlType="submit" loading={save.isPending} disabled={debit !== credit || debit === 0} block size="large">Post journal</Button>
      </Form>
    </div>
  );
}
