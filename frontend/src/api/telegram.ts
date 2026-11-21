export interface TelegramWebAppBridge {
  initData?: string;
  ready?: () => void;
  expand?: () => void;
  platform?: string;
  onEvent?: (eventType: string, callback: () => void) => void;
  offEvent?: (eventType: string, callback: () => void) => void;
}

declare global {
  interface Window {
    Telegram?: {
      WebApp?: TelegramWebAppBridge;
    };
  }
}

export function getTelegramWebApp(): TelegramWebAppBridge | undefined {
  return window.Telegram?.WebApp;
}

export function initTelegramBridge(onResume?: () => void): string {
  const tg = getTelegramWebApp();
  tg?.ready?.();
  tg?.expand?.();

  if (onResume && tg?.onEvent) {
    tg.onEvent('viewportChanged', onResume);
  }

  return tg?.initData ?? '';
}

export function createIdempotencyKey(prefix = 'op'): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `${prefix}-${crypto.randomUUID()}`;
  }
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}
