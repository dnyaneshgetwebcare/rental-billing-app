import { useNavigate } from 'react-router-dom';
import { Button, Card, Form, Input } from 'antd';
import { api } from '../api/client';
import { useSave } from '../api/hooks';
import { useAuth } from '../auth/AuthContext';

export default function PasswordPage() {
  const [form] = Form.useForm();
  const { logout } = useAuth();
  const navigate = useNavigate();
  const save = useSave((v: { current_password: string; new_password: string }) => api.post('/auth/change-password', v), {
    form,
    success: 'Password changed. Please log in again.',
    onSuccess: async () => {
      await logout();
      navigate('/login');
    },
  });
  return (
    <div className="page" style={{ maxWidth: 420 }}>
      <Card title="Change password">
        <Form form={form} layout="vertical" onFinish={(v) => save.mutate(v)}>
          <Form.Item name="current_password" label="Current password" rules={[{ required: true }]}>
            <Input.Password autoComplete="current-password" />
          </Form.Item>
          <Form.Item name="new_password" label="New password" rules={[{ required: true, min: 8 }]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={save.isPending}>Change password</Button>
        </Form>
      </Card>
    </div>
  );
}
