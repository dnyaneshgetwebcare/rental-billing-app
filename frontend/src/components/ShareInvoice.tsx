import { Button, message } from 'antd';
import { WhatsAppOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

interface ShareLink {
  url: string;
  message: string;
  phone: string | null;
  whatsapp_url: string;
}

type ShareNavigator = Navigator & { canShare?: (d: ShareData) => boolean };

const canShareFiles = (): boolean => {
  const nav = navigator as ShareNavigator;
  return !!nav.share && !!nav.canShare;
};

/**
 * Shares the invoice PDF on WhatsApp. On phones that support the Web Share API with files the PDF itself
 * is attached; otherwise WhatsApp opens with a message containing a signed 7-day download link.
 * The link and PDF are fetched in advance so the tap itself opens the share sheet or wa.me synchronously
 * (browsers block popups / share calls made after an await).
 */
export default function ShareInvoice({ invoiceId, number, block }: { invoiceId: number; number: string; block?: boolean }) {
  const link = useQuery({ queryKey: ['share-link', invoiceId], queryFn: () => api.post<ShareLink>(`/invoices/${invoiceId}/share-link`), staleTime: 3_600_000 });
  const pdf = useQuery({
    queryKey: ['invoice-pdf', invoiceId],
    queryFn: async () => new File([await api.blob(`/invoices/${invoiceId}/pdf`)], `Invoice-${number.replace(/[^\w-]+/g, '_')}.pdf`, { type: 'application/pdf' }),
    enabled: canShareFiles(),
    staleTime: 300_000,
  });

  const share = () => {
    const l = link.data;
    if (!l) return;
    const nav = navigator as ShareNavigator;
    if (pdf.data && nav.canShare?.({ files: [pdf.data] })) {
      nav.share({ files: [pdf.data], text: l.message }).catch((e: DOMException) => {
        if (e.name !== 'AbortError') window.open(l.whatsapp_url, '_blank', 'noopener');
      });
      return;
    }
    window.open(l.whatsapp_url, '_blank', 'noopener');
    if (!l.phone) message.info('No WhatsApp number saved for this tenant. Choose the chat in WhatsApp.');
  };

  return (
    <Button
      icon={<WhatsAppOutlined />}
      onClick={share}
      loading={link.isLoading || (canShareFiles() && pdf.isLoading)}
      disabled={link.isError}
      block={block}
      style={{ color: '#25D366', borderColor: '#25D366' }}
    >
      WhatsApp
    </Button>
  );
}
