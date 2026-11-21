import type {
  ApiErrorEnvelope,
  AuthTelegramResponse,
  BuildingItem,
  GameSyncResponse,
  ResourceKey,
} from '../types/game';

export class ApiClientError extends Error {
  public readonly status: number;
  public readonly code: string;
  public readonly requestId?: string;
  public readonly details?: Record<string, unknown>;

  constructor(
    status: number,
    code: string,
    message: string,
    requestId?: string,
    details?: Record<string, unknown>,
  ) {
    super(message);
    this.name = 'ApiClientError';
    this.status = status;
    this.code = code;
    this.requestId = requestId;
    this.details = details;
  }
}

export interface BuildingPreviewResponse {
  data: {
    can_build: boolean;
    cost: Partial<Record<ResourceKey, string>>;
    next_building_number?: number;
    position_valid?: boolean;
    balance_version?: string;
  };
}

export interface BuildingMutationResponse {
  data: {
    building?: BuildingItem;
    building_id?: number;
    previous_level?: number;
    current_level?: number;
    status?: string;
    demolished_at?: string;
    charged?: Partial<Record<ResourceKey, string>>;
    remaining_resources?: Partial<Record<ResourceKey, string>>;
  };
}

export interface AlchemyComponentInput {
  type: string;
  key: string;
  quantity: string;
}

const API_BASE = '/api/v1';

export class GameApiClient {
  private token: string | null = null;

  public setToken(token: string | null): void {
    this.token = token;
  }

  public getToken(): string | null {
    return this.token;
  }

  private async request<T>(
    path: string,
    options: {
      method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
      body?: unknown;
      idempotencyKey?: string;
      requiresAuth?: boolean;
    } = {},
  ): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };

    if (options.requiresAuth !== false && this.token) {
      headers.Authorization = `Bearer ${this.token}`;
    }

    if (options.idempotencyKey) {
      headers['Idempotency-Key'] = options.idempotencyKey;
    }

    const response = await fetch(`${API_BASE}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });

    let payload: unknown = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    if (!response.ok) {
      const errObj = (payload as ApiErrorEnvelope | null)?.error;
      const reqId = (payload as ApiErrorEnvelope | null)?.meta?.request_id;
      throw new ApiClientError(
        response.status,
        errObj?.code ?? 'HTTP_ERROR',
        errObj?.message ?? `Request failed with status ${response.status}`,
        reqId,
        errObj?.details,
      );
    }

    return payload as T;
  }

  public authTelegram(initData: string): Promise<AuthTelegramResponse> {
    return this.request<AuthTelegramResponse>('/auth/telegram', {
      method: 'POST',
      body: { init_data: initData },
      requiresAuth: false,
    });
  }

  public logout(idempotencyKey: string): Promise<unknown> {
    return this.request('/auth/logout', {
      method: 'POST',
      idempotencyKey,
    });
  }

  public syncGame(): Promise<GameSyncResponse> {
    return this.request<GameSyncResponse>('/game/sync', {
      method: 'GET',
    });
  }

  public getWorldPhase(): Promise<unknown> {
    return this.request('/world/phase', {
      method: 'GET',
    });
  }

  public getBuildingsCatalog(): Promise<unknown> {
    return this.request('/buildings/catalog', {
      method: 'GET',
    });
  }

  public previewBuilding(payload: {
    building_key: string;
    island_id: number;
    position: { x: number; y: number };
  }): Promise<BuildingPreviewResponse> {
    return this.request<BuildingPreviewResponse>('/buildings/preview', {
      method: 'POST',
      body: payload,
    });
  }

  public buildBuilding(
    payload: {
      building_key: string;
      island_id: number;
      position: { x: number; y: number };
    },
    idempotencyKey: string,
  ): Promise<BuildingMutationResponse> {
    return this.request<BuildingMutationResponse>('/buildings', {
      method: 'POST',
      body: payload,
      idempotencyKey,
    });
  }

  public upgradeBuilding(
    buildingId: number,
    targetLevel: number,
    idempotencyKey: string,
  ): Promise<BuildingMutationResponse> {
    return this.request<BuildingMutationResponse>(`/buildings/${buildingId}/upgrade`, {
      method: 'POST',
      body: { target_level: targetLevel },
      idempotencyKey,
    });
  }

  public moveBuilding(
    buildingId: number,
    payload: { island_id: number; position: { x: number; y: number } },
    idempotencyKey: string,
  ): Promise<BuildingMutationResponse> {
    return this.request<BuildingMutationResponse>(`/buildings/${buildingId}/position`, {
      method: 'PATCH',
      body: payload,
      idempotencyKey,
    });
  }

  public demolishBuilding(
    buildingId: number,
    idempotencyKey: string,
  ): Promise<BuildingMutationResponse> {
    return this.request<BuildingMutationResponse>(`/buildings/${buildingId}`, {
      method: 'DELETE',
      idempotencyKey,
    });
  }

  public listExpeditions(params?: { status?: string; limit?: number; cursor?: string }): Promise<unknown> {
    const qs = new URLSearchParams();
    if (params?.status) qs.set('status', params.status);
    if (params?.limit) qs.set('limit', String(params.limit));
    if (params?.cursor) qs.set('cursor', params.cursor);
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    return this.request(`/expeditions${suffix}`, { method: 'GET' });
  }

  public startExpedition(
    payload: { type: 'surface' | 'deep'; resident_ids: number[] },
    idempotencyKey: string,
  ): Promise<unknown> {
    return this.request('/expeditions', {
      method: 'POST',
      body: payload,
      idempotencyKey,
    });
  }

  public claimExpedition(expeditionId: number, idempotencyKey: string): Promise<unknown> {
    return this.request(`/expeditions/${expeditionId}/claim`, {
      method: 'POST',
      idempotencyKey,
    });
  }

  public runAlchemyExperiment(
    components: AlchemyComponentInput[],
    idempotencyKey: string,
  ): Promise<unknown> {
    return this.request('/alchemy/experiment', {
      method: 'POST',
      body: { components },
      idempotencyKey,
    });
  }

  public getAlchemyArchive(): Promise<unknown> {
    return this.request('/alchemy/archive', { method: 'GET' });
  }
}

export const gameApi = new GameApiClient();
