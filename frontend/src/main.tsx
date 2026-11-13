import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App as AntApp, ConfigProvider } from 'antd';
import enGB from 'antd/locale/en_GB';
import dayjs from 'dayjs';
import 'dayjs/locale/en-gb';
import { registerSW } from 'virtual:pwa-register';
import { AuthProvider } from './auth/AuthContext';
import { I18nProvider } from './i18n';
import App from './App';
import './styles.css';

dayjs.locale('en-gb');
registerSW({ immediate: true });

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: (count, err: any) => count < 1 && !(err?.status >= 400 && err?.status < 500),
      refetchOnWindowFocus: false,
    },
  },
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ConfigProvider
      locale={enGB}
      theme={{
        token: {
          colorPrimary: '#206bc4',
          colorInfo: '#206bc4',
          colorLink: '#206bc4',
          borderRadius: 4,
          fontFamily: "Inter, 'Nirmala UI', 'Noto Sans Devanagari', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
          colorBgLayout: '#f5f7fb',
          colorText: '#1d273b',
          colorBorder: '#dadfe5',
        },
        components: {
          Button: { fontWeight: 500, primaryShadow: 'none', defaultShadow: 'none' },
          Card: { headerFontSize: 14 },
        },
      }}
    >
      <AntApp>
        <QueryClientProvider client={queryClient}>
          <I18nProvider>
            <BrowserRouter>
              <AuthProvider>
                <App />
              </AuthProvider>
            </BrowserRouter>
          </I18nProvider>
        </QueryClientProvider>
      </AntApp>
    </ConfigProvider>
  </React.StrictMode>,
);
