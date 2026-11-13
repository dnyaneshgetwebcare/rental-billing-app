import { useNavigate } from 'react-router-dom';
import { Button, Card, List, Tag, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useAuth } from '../auth/AuthContext';
import { LanguageSwitch, useI18n } from '../i18n';

export default function CompanySelectPage() {
  const { companies, selectCompany, company } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  return (
    <div style={{ maxWidth: 520, margin: '40px auto', padding: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}><LanguageSwitch /></div>
      <Card>
        <Typography.Title level={4}>{t('company.choose')}</Typography.Title>
        {companies.length === 0 && (
          <Typography.Paragraph type="secondary">{t('company.none')}</Typography.Paragraph>
        )}
        <List
          dataSource={companies}
          renderItem={(c) => (
            <List.Item
              style={{ cursor: 'pointer' }}
              onClick={() => {
                selectCompany(c.id);
                navigate('/', { replace: true });
              }}
              extra={<Tag color={c.id === company?.id ? 'blue' : undefined}>{c.role}</Tag>}
            >
              <List.Item.Meta title={c.name} description={c.gstin ?? `State ${c.state_code}`} />
            </List.Item>
          )}
        />
        <Button icon={<PlusOutlined />} block style={{ marginTop: 12 }} onClick={() => navigate('/companies/new')}>
          {t('company.new')}
        </Button>
      </Card>
    </div>
  );
}
