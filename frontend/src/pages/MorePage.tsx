import { Link } from 'react-router-dom';
import { Card, List } from 'antd';
import { NAV } from '../layouts/AppLayout';
import { useI18n } from '../i18n';

/** Mobile menu: everything that does not fit in the bottom tab bar. */
export default function MorePage() {
  const { t } = useI18n();
  return (
    <div className="page">
      {NAV.map((g) => (
        <Card key={g.id} title={t(g.label)} size="small" style={{ marginBottom: 12 }}>
          <List
            size="small"
            dataSource={g.items}
            renderItem={(i) => (
              <List.Item>
                <Link to={i.to} style={{ display: 'block', width: '100%' }}>{t(i.label)}</Link>
              </List.Item>
            )}
          />
        </Card>
      ))}
    </div>
  );
}
