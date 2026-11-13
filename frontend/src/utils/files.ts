import { message } from 'antd';
import { api } from '../api/client';
import { showError } from '../api/hooks';

export function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/** Authenticated download of a report/PDF. */
export async function download(path: string, filename: string, query?: Record<string, string | number | undefined>) {
  const hide = message.loading('Preparing file…', 0);
  try {
    saveBlob(await api.blob(path, query), filename);
  } catch (e) {
    showError(e);
  } finally {
    hide();
  }
}

/** Opens an authenticated PDF in a new tab (falls back to download when popups are blocked). */
export async function openPdf(path: string, filename: string) {
  const win = window.open('', '_blank');
  try {
    const blob = await api.blob(path);
    const url = URL.createObjectURL(blob);
    if (win) win.location.href = url;
    else saveBlob(blob, filename);
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    win?.close();
    showError(e);
  }
}

/**
 * Shrinks large phone photos before upload (saves mobile data); the server re-encodes to WebP anyway.
 * Non-images and small images are returned unchanged.
 */
export async function shrinkImage(file: File, max = 2000, quality = 0.85): Promise<File> {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 600 * 1024) return file;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' } as ImageBitmapOptions);
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch {
    return file;
  }
}
