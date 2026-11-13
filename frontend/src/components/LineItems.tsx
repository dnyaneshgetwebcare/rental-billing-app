import { Button, Card, Col, Descriptions, Form, Input, InputNumber, Row, type FormInstance } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { AccountSelect, GstRateSelect } from './Selects';
import { fromPaise, gstTotals, type GstLineInput } from '../utils/money';
import Money from './Money';

export interface LineValue extends GstLineInput {
  description?: string;
  sac?: string;
  account_id?: number;
}

/**
 * Editable document lines with a live GST preview. The server recalculates on save.
 */
export function LineItems({
  form,
  interState,
  accountType,
  accountRequired,
  defaultLine,
}: {
  form: FormInstance;
  interState: boolean;
  accountType?: string | string[];
  accountRequired?: boolean;
  defaultLine: LineValue;
}) {
  const lines: LineValue[] = Form.useWatch('lines', form) ?? [];
  const totals = gstTotals(lines, interState);

  return (
    <>
      <Form.List name="lines">
        {(fields, { add, remove }) => (
          <>
            {fields.map((field, i) => (
              <Card key={field.key} size="small" style={{ marginBottom: 8 }} styles={{ body: { paddingBottom: 0 } }}>
                <Row gutter={8}>
                  <Col xs={24} md={accountType ? 9 : 12}>
                    <Form.Item name={[field.name, 'description']} label={i === 0 ? 'Description' : undefined} rules={[{ required: true, message: 'Required' }]}>
                      <Input placeholder="Description" />
                    </Form.Item>
                  </Col>
                  {accountType && (
                    <Col xs={24} md={6}>
                      <Form.Item name={[field.name, 'account_id']} label={i === 0 ? 'Account' : undefined} rules={accountRequired ? [{ required: true, message: 'Required' }] : []}>
                        <AccountSelect type={accountType} cashBank={false} allowClear={!accountRequired} placeholder={accountRequired ? 'Account' : 'Default account'} />
                      </Form.Item>
                    </Col>
                  )}
                  <Col xs={6} md={2}>
                    <Form.Item name={[field.name, 'qty']} label={i === 0 ? 'Qty' : undefined}>
                      <InputNumber min={0} style={{ width: '100%' }} placeholder="1" />
                    </Form.Item>
                  </Col>
                  <Col xs={10} md={3}>
                    <Form.Item name={[field.name, 'rate']} label={i === 0 ? 'Rate' : undefined} rules={[{ required: true, message: 'Required' }]}>
                      <InputNumber min={0} style={{ width: '100%' }} placeholder="0.00" inputMode="decimal" />
                    </Form.Item>
                  </Col>
                  <Col xs={8} md={2}>
                    <Form.Item name={[field.name, 'gst_rate']} label={i === 0 ? 'GST' : undefined}>
                      <GstRateSelect />
                    </Form.Item>
                  </Col>
                  <Col xs={20} md={accountType ? 1 : 3} style={{ display: 'flex', alignItems: i === 0 ? 'center' : 'flex-start', paddingTop: i === 0 ? 22 : 5 }}>
                    <Money value={fromPaise(totals.lines[i]?.total ?? 0)} />
                  </Col>
                  <Col xs={4} md={1} style={{ paddingTop: i === 0 ? 30 : 0, textAlign: 'right' }}>
                    {fields.length > 1 && <Button type="text" danger icon={<DeleteOutlined />} onClick={() => remove(field.name)} />}
                  </Col>
                </Row>
              </Card>
            ))}
            <Button icon={<PlusOutlined />} onClick={() => add({ ...defaultLine, description: '' })} style={{ marginBottom: 12 }}>
              Add line
            </Button>
          </>
        )}
      </Form.List>
      <GstTotalsView totals={totals} interState={interState} />
    </>
  );
}

export function GstTotalsView({ totals, interState }: { totals: ReturnType<typeof gstTotals>; interState: boolean }) {
  return (
    <Descriptions size="small" column={1} bordered style={{ maxWidth: 360, marginLeft: 'auto' }}>
      <Descriptions.Item label="Taxable"><Money value={fromPaise(totals.taxable)} /></Descriptions.Item>
      {interState ? (
        <Descriptions.Item label="IGST"><Money value={fromPaise(totals.igst)} /></Descriptions.Item>
      ) : (
        <>
          <Descriptions.Item label="CGST"><Money value={fromPaise(totals.cgst)} /></Descriptions.Item>
          <Descriptions.Item label="SGST"><Money value={fromPaise(totals.sgst)} /></Descriptions.Item>
        </>
      )}
      {totals.roundOff !== 0 && <Descriptions.Item label="Round off"><Money value={fromPaise(totals.roundOff)} /></Descriptions.Item>}
      <Descriptions.Item label="Total"><Money value={fromPaise(totals.total)} strong /></Descriptions.Item>
    </Descriptions>
  );
}

/** Converts InputNumber values (numbers) to the API's decimal strings. */
export function linesPayload(lines: LineValue[] | undefined) {
  return (lines ?? []).map((l) => ({
    ...l,
    qty: l.qty === undefined || l.qty === null || l.qty === '' ? '1' : String(l.qty),
    rate: l.rate === undefined || l.rate === null ? '' : String(l.rate),
    gst_rate: l.gst_rate === undefined || l.gst_rate === null ? '0' : String(l.gst_rate),
  }));
}
