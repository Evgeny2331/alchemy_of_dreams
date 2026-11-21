export type ResourceKey =
  | 'fog'
  | 'silence'
  | 'memories'
  | 'metaphors'
  | 'nightmare_shards';

export interface ApiErrorEnvelope {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
  meta?: {
    request_id?: string;
  };
}

export interface AuthTelegramResponse {
  data: {
    session_token: string;
    expires_at: string;
    player: {
      id: number;
      first_name?: string;
      username?: string | null;
      is_new_player?: boolean;
    };
  };
}

export interface IslandItem {
  id: number;
  island_index: number;
  is_unlocked: boolean;
}

export interface BuildingItem {
  id: number;
  island_id: number;
  building_key?: string;
  building_type?: string;
  level: number;
  status: 'active' | 'building' | 'demolished';
  position_x?: number;
  position_y?: number;
  position?: { x: number; y: number };
}

export interface GameSyncResponse {
  data: {
    player?: { id: number; first_name?: string };
    city: {
      id: number;
      name: string;
      economy_calculated_at: string;
      resources?: Partial<Record<ResourceKey, string>>;
      storage?: Record<string, { current: string; capacity: string }>;
      islands?: IslandItem[];
      buildings?: BuildingItem[];
    };
    resources?: Partial<Record<ResourceKey, string>>;
    islands?: IslandItem[];
    buildings?: BuildingItem[];
    world_phase?: {
      key?: string;
      phase_name?: string;
      starts_at?: string;
      ends_at?: string;
      multipliers?: { fog?: string };
      multiplier_fog?: string;
    };
    server_time?: string;
    meta?: {
      request_id?: string;
      balance_version?: string;
    };
  };
}
