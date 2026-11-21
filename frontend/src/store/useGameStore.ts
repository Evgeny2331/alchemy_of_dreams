import { create } from 'zustand';
import {
  ApiClientError,
  gameApi,
  type AlchemyComponentInput,
  type BuildingPreviewResponse,
} from '../api/client';
import { createIdempotencyKey, initTelegramBridge } from '../api/telegram';
import type { BuildingItem, GameSyncResponse, IslandItem, ResourceKey } from '../types/game';

const SESSION_STORAGE_KEY = 'aod_session_token';
const SYNC_CACHE_KEY = 'aod_last_sync_cache';

export interface ExpeditionSummary {
  id: number;
  type: string;
  status: string;
  started_at?: string;
  ends_at?: string;
}

export interface ArchiveRecipeItem {
  key: string;
  category: string;
  unlocked_at?: string;
}

export interface GameUiState {
  initialized: boolean;
  loading: boolean;
  mutating: boolean;
  error: string | null;
  lastErrorCode: string | null;
  lastRequestId: string | null;
  lastNotice: string | null;
  sessionToken: string | null;
  playerName: string;
  cityName: string;
  economyCalculatedAt: string | null;
  phaseKey: string;
  resources: Record<ResourceKey, string>;
  islands: IslandItem[];
  buildings: BuildingItem[];
  expeditions: ExpeditionSummary[];
  archiveRecipes: ArchiveRecipeItem[];
  selectedBuildingId: number | null;
  preview: BuildingPreviewResponse['data'] | null;
  bootstrap: (devFallbackInitData?: string) => Promise<void>;
  syncNow: () => Promise<void>;
  logout: () => Promise<void>;
  selectBuilding: (buildingId: number | null) => void;
  previewBuildAt: (buildingKey: string, islandId: number, x: number, y: number) => Promise<void>;
  buildAt: (buildingKey: string, islandId: number, x: number, y: number) => Promise<void>;
  upgradeSelectedBuilding: () => Promise<void>;
  moveSelectedBuilding: (islandId: number, x: number, y: number) => Promise<void>;
  demolishSelectedBuilding: () => Promise<void>;
  loadExpeditions: () => Promise<void>;
  startExpedition: (type: 'surface' | 'deep', residentIds: number[]) => Promise<void>;
  claimExpedition: (expeditionId: number) => Promise<void>;
  loadAlchemyArchive: () => Promise<void>;
  runAlchemyExperiment: (components: AlchemyComponentInput[]) => Promise<void>;
}

const DEFAULT_RESOURCES: Record<ResourceKey, string> = {
  fog: '0.000000',
  silence: '0.000000',
  memories: '0.000000',
  metaphors: '0.000000',
  nightmare_shards: '0.000000',
};

function readCachedSync(): Partial<GameSyncResponse['data']> | null {
  try {
    const raw = localStorage.getItem(SYNC_CACHE_KEY);
    return raw ? (JSON.parse(raw) as Partial<GameSyncResponse['data']>) : null;
  } catch {
    return null;
  }
}

function writeCachedSync(data: GameSyncResponse['data']): void {
  try {
    localStorage.setItem(SYNC_CACHE_KEY, JSON.stringify(data));
  } catch {
    // Игнорируем ошибки квоты локального кэша
  }
}

async function runIdempotentWithNetworkRetry<T>(
  action: (idemKey: string) => Promise<T>,
  prefix: string,
): Promise<T> {
  const idemKey = createIdempotencyKey(prefix);
  try {
    return await action(idemKey);
  } catch (err) {
    if (!(err instanceof ApiClientError)) {
      return await action(idemKey);
    }
    throw err;
  }
}

export const useGameStore = create<GameUiState>((set, get) => {
  const cached = readCachedSync();
  const savedToken = (() => {
    try {
      return localStorage.getItem(SESSION_STORAGE_KEY);
    } catch {
      return null;
    }
  })();

  if (savedToken) {
    gameApi.setToken(savedToken);
  }

  return {
    initialized: false,
    loading: false,
    mutating: false,
    error: null,
    lastErrorCode: null,
    lastRequestId: null,
    lastNotice: null,
    sessionToken: savedToken,
    playerName: cached?.player?.first_name ?? 'Сновидец',
    cityName: cached?.city?.name ?? 'Город Снов',
    economyCalculatedAt: cached?.city?.economy_calculated_at ?? null,
    phaseKey: cached?.world_phase?.key ?? cached?.world_phase?.phase_name ?? 'twilight',
    resources: {
      ...DEFAULT_RESOURCES,
      ...(cached?.resources ?? cached?.city?.resources ?? {}),
    },
    islands: cached?.islands ?? cached?.city?.islands ?? [],
    buildings: cached?.buildings ?? cached?.city?.buildings ?? [],
    expeditions: [],
    archiveRecipes: [],
    selectedBuildingId: null,
    preview: null,

    selectBuilding: (buildingId) => set({ selectedBuildingId: buildingId }),

    syncNow: async () => {
      if (!gameApi.getToken()) {
        return;
      }
      set({ loading: true, error: null, lastErrorCode: null, lastRequestId: null });
      try {
        const res = await gameApi.syncGame();
        const d = res.data;
        writeCachedSync(d);

        set({
          loading: false,
          initialized: true,
          playerName: d.player?.first_name ?? get().playerName,
          cityName: d.city?.name ?? get().cityName,
          economyCalculatedAt: d.city?.economy_calculated_at ?? get().economyCalculatedAt,
          phaseKey: d.world_phase?.key ?? d.world_phase?.phase_name ?? get().phaseKey,
          resources: {
            ...DEFAULT_RESOURCES,
            ...(d.resources ?? d.city?.resources ?? {}),
          },
          islands: d.islands ?? d.city?.islands ?? [],
          buildings: d.buildings ?? d.city?.buildings ?? [],
        });
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        if (apiErr?.status === 401) {
          gameApi.setToken(null);
          localStorage.removeItem(SESSION_STORAGE_KEY);
          set({ sessionToken: null });
        }
        set({
          loading: false,
          initialized: true,
          error: apiErr?.message ?? 'Не удалось синхронизировать состояние с сервером.',
          lastErrorCode: apiErr?.code ?? 'SYNC_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
      }
    },

    bootstrap: async (devFallbackInitData = '') => {
      set({ loading: true, error: null, lastErrorCode: null, lastRequestId: null });
      const initDataFromTg = initTelegramBridge(() => {
        void get().syncNow();
      });
      const initData = initDataFromTg || devFallbackInitData;

      try {
        if (!get().sessionToken && initData) {
          const authRes = await gameApi.authTelegram(initData);
          const token = authRes.data.session_token;
          gameApi.setToken(token);
          localStorage.setItem(SESSION_STORAGE_KEY, token);
          set({
            sessionToken: token,
            playerName: authRes.data.player.first_name ?? 'Сновидец',
          });
        }

        if (gameApi.getToken()) {
          await get().syncNow();
          await Promise.all([get().loadExpeditions(), get().loadAlchemyArchive()]);
        } else {
          set({
            loading: false,
            initialized: true,
            error: 'Нет Telegram initData и активной сессии. Укажите тестовый initData для локальной проверки.',
            lastErrorCode: 'NO_SESSION',
            lastRequestId: null,
          });
        }
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          loading: false,
          initialized: true,
          error: apiErr?.message ?? 'Ошибка инициализации сессии.',
          lastErrorCode: apiErr?.code ?? 'BOOTSTRAP_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
      }
    },

    logout: async () => {
      const prevToken = get().sessionToken;
      try {
        if (prevToken) {
          await runIdempotentWithNetworkRetry((key) => gameApi.logout(key), 'logout');
        }
      } catch {
        // Очищаем локальную сессию даже при истечении токена
      } finally {
        gameApi.setToken(null);
        localStorage.removeItem(SESSION_STORAGE_KEY);
        set({
          sessionToken: null,
          selectedBuildingId: null,
          preview: null,
          expeditions: [],
          archiveRecipes: [],
        });
      }
    },

    previewBuildAt: async (buildingKey, islandId, x, y) => {
      if (!gameApi.getToken()) return;
      set({ error: null, lastErrorCode: null, lastRequestId: null });
      try {
        const res = await gameApi.previewBuilding({
          building_key: buildingKey,
          island_id: islandId,
          position: { x, y },
        });
        set({ preview: res.data });
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          preview: null,
          error: apiErr?.message ?? 'Не удалось рассчитать предварительную стоимость.',
          lastErrorCode: apiErr?.code ?? 'PREVIEW_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
      }
    },

    buildAt: async (buildingKey, islandId, x, y) => {
      if (!gameApi.getToken()) return;
      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        await runIdempotentWithNetworkRetry(
          (key) =>
            gameApi.buildBuilding(
              { building_key: buildingKey, island_id: islandId, position: { x, y } },
              key,
            ),
          'build',
        );
        set({ mutating: false, preview: null, lastNotice: 'Здание построено.' });
        await get().syncNow();
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Не удалось построить здание.',
          lastErrorCode: apiErr?.code ?? 'BUILD_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },

    upgradeSelectedBuilding: async () => {
      const id = get().selectedBuildingId;
      if (!id || !gameApi.getToken()) return;
      const target = get().buildings.find((b) => b.id === id);
      const nextLevel = (target?.level ?? 1) + 1;

      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        await runIdempotentWithNetworkRetry(
          (key) => gameApi.upgradeBuilding(id, nextLevel, key),
          'upgrade',
        );
        set({ mutating: false, lastNotice: `Здание #${id} улучшено до уровня ${nextLevel}.` });
        await get().syncNow();
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Не удалось улучшить здание.',
          lastErrorCode: apiErr?.code ?? 'UPGRADE_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },

    moveSelectedBuilding: async (islandId, x, y) => {
      const id = get().selectedBuildingId;
      if (!id || !gameApi.getToken()) return;

      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        await runIdempotentWithNetworkRetry(
          (key) => gameApi.moveBuilding(id, { island_id: islandId, position: { x, y } }, key),
          'move',
        );
        set({ mutating: false, lastNotice: `Здание #${id} перемещено в (${x}, ${y}).` });
        await get().syncNow();
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Не удалось переместить здание.',
          lastErrorCode: apiErr?.code ?? 'MOVE_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },

    demolishSelectedBuilding: async () => {
      const id = get().selectedBuildingId;
      if (!id || !gameApi.getToken()) return;

      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        await runIdempotentWithNetworkRetry(
          (key) => gameApi.demolishBuilding(id, key),
          'demolish',
        );
        set({ mutating: false, selectedBuildingId: null, lastNotice: `Здание #${id} снесено.` });
        await get().syncNow();
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Не удалось снести здание.',
          lastErrorCode: apiErr?.code ?? 'DEMOLISH_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },

    loadExpeditions: async () => {
      if (!gameApi.getToken()) return;
      try {
        const res = (await gameApi.listExpeditions({ limit: 20 })) as {
          data?: { items?: ExpeditionSummary[] };
        };
        set({ expeditions: res.data?.items ?? [] });
      } catch {
        // Ошибки загрузки списка не ломают основной экран
      }
    },

    startExpedition: async (type, residentIds) => {
      if (!gameApi.getToken()) return;
      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        await runIdempotentWithNetworkRetry(
          (key) => gameApi.startExpedition({ type, resident_ids: residentIds }, key),
          'exp-start',
        );
        set({ mutating: false, lastNotice: `Экспедиция (${type}) запущена.` });
        await Promise.all([get().syncNow(), get().loadExpeditions()]);
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Не удалось запустить экспедицию.',
          lastErrorCode: apiErr?.code ?? 'EXPEDITION_START_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },

    claimExpedition: async (expeditionId) => {
      if (!gameApi.getToken()) return;
      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        await runIdempotentWithNetworkRetry(
          (key) => gameApi.claimExpedition(expeditionId, key),
          'exp-claim',
        );
        set({ mutating: false, lastNotice: `Награда экспедиции #${expeditionId} получена.` });
        await Promise.all([get().syncNow(), get().loadExpeditions()]);
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Не удалось получить награду экспедиции.',
          lastErrorCode: apiErr?.code ?? 'EXPEDITION_CLAIM_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },

    loadAlchemyArchive: async () => {
      if (!gameApi.getToken()) return;
      try {
        const res = (await gameApi.getAlchemyArchive()) as {
          data?: { recipes?: ArchiveRecipeItem[] };
        };
        set({ archiveRecipes: res.data?.recipes ?? [] });
      } catch {
        // Игнорируем ошибку загрузки архива при фоновом обновлении
      }
    },

    runAlchemyExperiment: async (components) => {
      if (!gameApi.getToken()) return;
      set({ mutating: true, error: null, lastErrorCode: null, lastRequestId: null, lastNotice: null });
      try {
        const res = (await runIdempotentWithNetworkRetry(
          (key) => gameApi.runAlchemyExperiment(components, key),
          'alchemy-exp',
        )) as {
          data?: { success?: boolean; recipe_unlocked?: boolean; recipe?: { key?: string } };
        };
        const unlockedKey = res.data?.recipe?.key;
        const msg = res.data?.success
          ? unlockedKey
            ? `Эксперимент успешен! Открыт рецепт: ${unlockedKey}`
            : 'Эксперимент успешен.'
          : 'Эксперимент не открыл новый рецепт.';
        set({ mutating: false, lastNotice: msg });
        await Promise.all([get().syncNow(), get().loadAlchemyArchive()]);
      } catch (err) {
        const apiErr = err instanceof ApiClientError ? err : null;
        set({
          mutating: false,
          error: apiErr?.message ?? 'Ошибка алхимического эксперимента.',
          lastErrorCode: apiErr?.code ?? 'ALCHEMY_FAILED',
          lastRequestId: apiErr?.requestId ?? null,
        });
        await get().syncNow();
      }
    },
  };
});
