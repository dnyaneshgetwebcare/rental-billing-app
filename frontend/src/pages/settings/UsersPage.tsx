import { useState } from 'react';
import { Button, Form, Input, Modal, Popconfirm, Select, Table, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useSave } from '../../api/hooks';
import { useAuth } from '../../auth/AuthContext';
import PageHeader from '../../components/PageHeader';
import { useI18n } from '../../i18n';

interface CompanyUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: string;
  last_login_at: string | null;
}

export default function UsersPage() {
  const { can, user: me } = useAuth();
  const { t } = useI18n();
  const roles = [
    { value: 'admin', label: t('users.roleAdmin') },
    { value: 'accountant', label: t('users.roleAccountant') },
    { value: 'staff', label: t('users.roleStaff') },
  ];
  const [open, setOpen] = useState(false);
  const [form] = Form.useForm();
  const list = useQuery({ queryKey: ['/users'], queryFn: () => api.get<CompanyUser[]>('/users'), enabled: can() });

  const add = useSave((v: Record<string, unknown>) => api.post('/users', v), {
    form,
    invalidate: ['/users'],
    success: t('users.added'),
    onSuccess: () => {
      setOpen(false);
      form.resetFields();
    },
  });
  const setRole = useSave(({ id, role }: { id: number; role: string }) => api.put(`/users/${id}`, { role }), { invalidate: ['/users'], success: t('users.roleUpdated') });
  const remove = useSave((id: number) => api.del(`/users/${id}`), { invalidate: ['/users'], success: t('users.removed') });

  if (!can()) return <div className="page">{t('users.onlyAdmin')}</div>;

  return (
    <div className="page">
      <PageHeader title={t('users.title')} extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => setOpen(true)}>{t('users.add')}</Button>} />
      <Table
        rowKey="id"
        loading={list.isLoading}
        dataSource={list.data ?? []}
        pagination={false}
        scroll={{ x: 600 }}
        columns={[
          { title: t('users.name'), dataIndex: 'name' },
          { title: t('users.email'), dataIndex: 'email' },
          {
            title: t('users.role'),
            dataIndex: 'role',
            render: (role: string, r: CompanyUser) =>
              r.id === me?.id ? <Tag color="blue">{role} ({t('users.you')})</Tag> : (
                <Select size="small" value={role} style={{ width: 130 }} options={roles.map((x) => ({ value: x.value, label: x.value }))} onChange={(v) => setRole.mutate({ id: r.id, role: v })} />
              ),
          },
          { title: t('users.lastLogin'), dataIndex: 'last_login_at' },
          {
            title: '',
            render: (_: unknown, r: CompanyUser) =>
              r.id !== me?.id && (
                <Popconfirm title={t('users.removeAsk')} onConfirm={() => remove.mutate(r.id)}>
                  <Button size="small" danger>{t('users.remove')}</Button>
                </Popconfirm>
              ),
          },
        ]}
      />
      <Modal title={t('users.add')} open={open} onCancel={() => setOpen(false)} onOk={() => form.submit()} confirmLoading={add.isPending} okText={t('users.add')}>
        <Form form={form} layout="vertical" initialValues={{ role: 'staff' }} onFinish={(v) => add.mutate(v)}>
          <Form.Item name="email" label={t('users.email')} rules={[{ required: true, type: 'email' }]} extra={t('users.emailHint')}>
            <Input inputMode="email" />
          </Form.Item>
          <Form.Item name="role" label={t('users.role')} rules={[{ required: true }]}><Select options={roles} /></Form.Item>
          <Form.Item name="name" label={t('users.name')} extra={t('users.nameHint')} rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="phone" label={t('users.phone')}><Input /></Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
