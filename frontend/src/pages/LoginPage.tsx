import { useEffect, useRef, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { Button, Card, Divider, Form, Input, Typography } from 'antd';
import { LockOutlined, MailOutlined } from '@ant-design/icons';
import { useAuth } from '../auth/AuthContext';
import { showError } from '../api/hooks';
import { LanguageSwitch, useI18n } from '../i18n';

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID ?? '';

export default function LoginPage() {
  const { user, companies, login, loginWithGoogle } = useAuth();
  const { t, lang } = useI18n();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [form] = Form.useForm();
  const googleRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!GOOGLE_CLIENT_ID || !googleRef.current) return;
    const parent = googleRef.current;
    let cancelled = false;
    const render = () => {
      const google = window.google;
      if (cancelled || !google || !parent.isConnected) return;
      parent.replaceChildren();
      google.accounts.id.initialize({
        client_id: GOOGLE_CLIENT_ID,
        callback: async (response) => {
          setBusy(true);
          try {
            const res = await loginWithGoogle(response.credential);
            navigate(res.companies.length === 0 ? '/companies/new' : '/', { replace: true });
          } catch (e) {
            showError(e);
          } finally {
            setBusy(false);
          }
        },
      });
      google.accounts.id.renderButton(parent, {
        theme: 'outline',
        size: 'large',
        width: 336,
        text: 'continue_with',
        shape: 'rectangular',
        locale: lang === 'mr' ? 'mr' : 'en',
      });
    };
    if (window.google) render();
    else {
      const script = document.createElement('script');
      script.src = 'https://accounts.google.com/gsi/client';
      script.async = true;
      script.onload = render;
      document.head.appendChild(script);
    }
    return () => { cancelled = true; };
  }, [lang, loginWithGoogle, navigate]);

  if (user) return <Navigate to={companies.length === 0 ? '/companies/new' : '/'} replace />;

  return (
    <div className="login-page">
      <div className="login-lang"><LanguageSwitch /></div>
      <Card className="login-card">
        <Typography.Title level={3} style={{ textAlign: 'center', marginTop: 0 }}>{t('login.title')}</Typography.Title>
        <div ref={googleRef} className="google-btn" />
        {GOOGLE_CLIENT_ID
          ? <Typography.Paragraph type="secondary" style={{ textAlign: 'center', marginTop: 12 }}>{t('login.googleHint')}</Typography.Paragraph>
          : <Typography.Paragraph type="secondary">{t('login.missingClient')}</Typography.Paragraph>}
        <Divider plain>{t('login.or')}</Divider>
        <Form
          form={form}
          layout="vertical"
          onFinish={async (v) => {
            setBusy(true);
            try {
              await login(v.email, v.password);
              navigate('/', { replace: true });
            } catch (e) {
              showError(e, form);
            } finally {
              setBusy(false);
            }
          }}
        >
          <Form.Item name="email" label={t('login.email')} rules={[{ required: true, type: 'email' }]}>
            <Input prefix={<MailOutlined />} autoComplete="username" inputMode="email" size="large" />
          </Form.Item>
          <Form.Item name="password" label={t('login.password')} rules={[{ required: true }]}>
            <Input.Password prefix={<LockOutlined />} autoComplete="current-password" size="large" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block size="large" loading={busy}>{t('login.submit')}</Button>
        </Form>
      </Card>
    </div>
  );
}
