import type { AppConfig, EmployeeHit, Floor, FloorListResponse, Pin } from './types';

export class ApiError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ApiError';
  }
}

export class Api {
  constructor(private readonly config: AppConfig) {}

  async floorList(): Promise<FloorListResponse> {
    return this.call('floorList');
  }

  async floorSave(id: number, name: string, sort: number, file: File | null): Promise<Floor> {
    const body = new FormData();
    body.set('sessid', this.config.sessid);
    body.set('id', String(id));
    body.set('name', name);
    body.set('sort', String(sort));
    if (file) {
      body.set('map', file, file.name);
    }
    const data = await this.post('floorSave', body);
    return data.floor as Floor;
  }

  async floorDelete(id: number): Promise<void> {
    const body = new FormData();
    body.set('sessid', this.config.sessid);
    body.set('id', String(id));
    await this.post('floorDelete', body);
  }

  async pinList(floorId: number): Promise<Pin[]> {
    const data = await this.call('pinList', { floorId });
    return (data.pins as Pin[]) ?? [];
  }

  async pinFind(userId: number): Promise<EmployeeHit | null> {
    const data = await this.call('pinFind', { userId });
    return (data.employee as EmployeeHit | null) ?? null;
  }

  async pinSave(floorId: number, userId: number, left: number, top: number): Promise<Pin> {
    const body = new FormData();
    body.set('sessid', this.config.sessid);
    body.set('floorId', String(floorId));
    body.set('userId', String(userId));
    body.set('left', String(left));
    body.set('top', String(top));
    const data = await this.post('pinSave', body);
    return data.pin as Pin;
  }

  async pinDelete(floorId: number, userId: number): Promise<void> {
    const body = new FormData();
    body.set('sessid', this.config.sessid);
    body.set('floorId', String(floorId));
    body.set('userId', String(userId));
    await this.post('pinDelete', body);
  }

  private actionUrl(action: keyof AppConfig['actions'], params: Record<string, string | number> = {}): string {
    const query = new URLSearchParams({ action: this.config.actions[action] });
    for (const [key, value] of Object.entries(params)) {
      query.set(key, String(value));
    }
    return `${this.config.ajaxUrl}?${query.toString()}`;
  }

  private async call(action: keyof AppConfig['actions'], params: Record<string, string | number> = {}): Promise<Record<string, unknown>> {
    const response = await fetch(this.actionUrl(action, params), { credentials: 'same-origin' });
    return this.unwrap(await response.json(), action);
  }

  private async post(action: keyof AppConfig['actions'], body: FormData): Promise<Record<string, unknown>> {
    const response = await fetch(this.actionUrl(action), {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    return this.unwrap(await response.json(), action);
  }

  private unwrap(payload: unknown, action: string): Record<string, unknown> {
    const data = payload as { status?: string; data?: Record<string, unknown>; errors?: { message?: string }[] };
    if (data && data.status === 'success') {
      return data.data ?? {};
    }
    const message = data?.errors?.[0]?.message || `Ошибка запроса (${action})`;
    throw new ApiError(String(message));
  }
}
