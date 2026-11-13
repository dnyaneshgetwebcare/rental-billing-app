import { useNavigate } from 'react-router-dom';
import { Alert, Button, Card, Col, DatePicker, Form, Input, Row } from 'antd';
import dayjs from 'dayjs';
import { api } from '../../api/client';
import { useGet, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import { LineItems, linesPayload, type LineValue } from '../../components/LineItems';
import PageHeader from '../../components/PageHeader';
import { StateSelect, VendorSelect } from '../../components/Selects';
import { toApiDate } from '../../utils/date';
import { STATES } from '../../utils/money';

const DEFAULT_LINE: LineValue = { description: '', qty: 1, gst_rate: '18.00' };

/** Vendor bill bought on credit (electricity, repairs, security agency...). Pay it later from Vendor payments. */
export default function PurchaseFormPage() {
  const navigate = useNavigate();
  const { company } = useAuth();
  const [form] = Form.useForm();
  const vendorId: number | undefined = Form.useWatch('vendor_id', form);
  const posOverride: string | undefined = Form.useWatch('place_of_supply', form);
  const vendor = useGet<{ state_code: string | null }>(vendorId ? `/vendors/${vendorId}` : null);
  const pos = posOverride || vendor.data?.state_code || company?.state_code || '';
  const interState = !!pos && pos !== company?.state_code;

  const save = useSave(
    (v: Record<string, any>) =>
      api.post<{ id: number }>('/purchases', {
        vendor_id: v.vendor_id,
        bill_no: v.bill_no,
        bill_date: toApiDate(v.bill_date),
        place_of_supply: v.place_of_supply ?? null,
        notes: v.notes,
        lines: linesPayload(v.lines),
      }),
    { form, invalidate: ['/purchases'], success: 'Purchase saved', onSuccess: (r) => navigate(`/purchases/${r.id}`, { replace: true }) },
  );

  return (
    <div className="page">
      <PageHeader title="New purchase bill" back />
      <Form form={form} layout="vertical" onFinish={save.mutate} initialValues={{ bill_date: dayjs(), lines: [DEFAULT_LINE] }}>
        <Card size="small" style={{ marginBottom: 12 }}>
          <Row gutter={12}>
            <Col xs={24} md={10}><Form.Item name="vendor_id" label="Vendor" rules={[{ required: true }]}><VendorSelect /></Form.Item></Col>
            <Col xs={12} md={7}><Form.Item name="bill_no" label="Vendor bill no." rules={[{ required: true }]}><Input /></Form.Item></Col>
            <Col xs={12} md={7}><Form.Item name="bill_date" label="Bill date" rules={[{ required: true }]}><DatePicker format="DD-MM-YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={10}>
              <Form.Item name="place_of_supply" label="Vendor state" tooltip="Defaults to the vendor's state">
                <StateSelect allowClear placeholder={pos ? `Auto: ${STATES[pos] ?? pos}` : 'Auto'} />
              </Form.Item>
            </Col>
          </Row>
          {vendorId && <Alert type="info" showIcon message={interState ? 'Inter-state: IGST input credit' : 'Same state: CGST + SGST input credit'} />}
        </Card>
        <LineItems form={form} interState={interState} accountType={['expense', 'asset']} accountRequired defaultLine={DEFAULT_LINE} />
        <Form.Item name="notes" label="Notes" style={{ marginTop: 12 }}><Input.TextArea rows={2} /></Form.Item>
        <Button type="primary" htmlType="submit" loading={save.isPending} block size="large">Save purchase</Button>
      </Form>
    </div>
  );
}
