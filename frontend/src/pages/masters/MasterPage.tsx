import { useState } from 'react';
import { Button, Card, Drawer, Form, Grid, Input, List, Popconfirm, Segmented, Space, Table, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { api } from '../../api/client';
import { useList, useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import PageHeader from '../../components/PageHeader';
import { MASTERS, type MasterResource, type Row } from './masterConfig';

export default function MasterPage({ resource }: { resource: MasterResource }) {
  const cfg = MASTERS[resource];
  const screens = Grid.useBreakpoint();
  const { can } = useAuth();
  const [form] = Form.useForm();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [active, setActive] = useState<string>('1');
  const [editing, setEditing] = useState<Row | null | 'new'>(null);

  const path = `/${resource}`;
  const list = useList<Row>(path, { page, per_page: cfg.perPage ?? 25, q, active });
  const writable = can(...cfg.writeRoles);

  const save = useSave(
    (v: Row) => (editing === 'new' || editing === null ? api.post<Row>(path, v) : api.put<Row>(`${path}/${editing.id}`, v)),
    { form, invalidate: [path], success: 'Saved', onSuccess: () => setEditing(null) },
  );
  const remove = useSave((id: number) => api.del(`${path}/${id}`), { invalidate: [path], success: 'Deleted', onSuccess: () => setEditing(null) });

  const open = (row: Row | 'new') => {
    form.resetFields();
    form.setFieldsValue(row === 'new' ? cfg.defaults ?? { is_active: true } : { ...row, is_active: !!row.is_active, is_default: !!row.is_default, is_cash_bank: !!row.is_cash_bank });
    setEditing(row);
  };

  const rows = list.data?.items ?? [];
  const pagination = { current: page, pageSize: cfg.perPage ?? 25, total: list.data?.total ?? 0, onChange: setPage, showSizeChanger: false };

  return (
    <div className="page">
      <PageHeader
        title={cfg.title}
        extra={
          <>
            <Input.Search allowClear placeholder="Search" onSearch={(v) => { setQ(v); setPage(1); }} style={{ width: 200 }} />
            <Segmented value={active} onChange={(v) => { setActive(String(v)); setPage(1); }} options={[{ label: 'Active', value: '1' }, { label: 'All', value: '' }]} />
            {writable && <Button type="primary" icon={<PlusOutlined />} onClick={() => open('new')}>New</Button>}
          </>
        }
      />

      {screens.md ? (
        <Table
          rowKey="id"
          loading={list.isFetching}
          dataSource={rows}
          pagination={pagination}
          scroll={{ x: 700 }}
          onRow={(r) => ({ onClick: () => writable && open(r), style: { cursor: writable ? 'pointer' : undefined } })}
          columns={[
            ...cfg.columns,
            { title: 'Status', dataIndex: 'is_active', width: 90, render: (v: number) => (v ? <Tag color="green">Active</Tag> : <Tag>Inactive</Tag>) },
            ...(cfg.rowActions ? [{ title: '', key: 'actions', width: 1, render: (_: unknown, r: Row) => <span onClick={(e) => e.stopPropagation()}>{cfg.rowActions!(r)}</span> }] : []),
          ]}
        />
      ) : (
        <List
          loading={list.isFetching}
          dataSource={rows}
          pagination={rows.length ? { ...pagination, size: 'small', simple: true } : false}
          renderItem={(r) => (
            <Card className="list-card" style={{ marginBottom: 8 }} onClick={() => writable && open(r)}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                <div>
                  <div style={{ fontWeight: 600 }}>{cfg.mobileTitle(r)}</div>
                  <div style={{ color: '#888', fontSize: 12 }}>{cfg.mobileSubtitle?.(r)}</div>
                </div>
                {!r.is_active && <Tag>Inactive</Tag>}
              </div>
              {cfg.rowActions && <div style={{ marginTop: 8 }} onClick={(e) => e.stopPropagation()}>{cfg.rowActions(r)}</div>}
            </Card>
          )}
        />
      )}

      <Drawer
        title={editing === 'new' ? `New ${cfg.singular}` : `Edit ${cfg.singular}`}
        open={editing !== null}
        onClose={() => setEditing(null)}
        width={screens.md ? 520 : '100%'}
        destroyOnClose
        extra={
          <Space>
            {editing !== 'new' && editing !== null && can('accountant') && (
              <Popconfirm title={`Delete this ${cfg.singular}?`} onConfirm={() => remove.mutate(editing.id as number)}>
                <Button danger>Delete</Button>
              </Popconfirm>
            )}
            <Button type="primary" loading={save.isPending} onClick={() => form.submit()}>Save</Button>
          </Space>
        }
      >
        <Form form={form} layout="vertical" onFinish={(v) => save.mutate(cfg.toPayload ? cfg.toPayload(v) : v)} onValuesChange={(c) => cfg.onValuesChange?.(c, form)}>
          <cfg.Fields form={form} editing={editing} />
        </Form>
      </Drawer>
    </div>
  );
}
