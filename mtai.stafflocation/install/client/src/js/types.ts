export interface Floor {
  id: number;
  name: string;
  sort: number;
  mapUrl: string;
}

export interface Pin {
  id: number;
  userId: number;
  name: string;
  position: string;
  avatar: string;
  left: number;
  top: number;
}

export interface Focus {
  userId: number;
  floorId: number;
  left: number;
  top: number;
  token?: number;
  openProfile?: boolean;
}

export interface EmployeeHit {
  userId: number;
  name: string;
  position: string;
  avatar: string;
  floorId: number;
  floorName: string;
  left: number;
  top: number;
}

export interface AppConfig {
  sessid: string;
  ajaxUrl: string;
  canEdit: boolean;
  defaultAvatar: string;
  focusUserId: number;
  focus: Focus | null;
  actions: {
    floorList: string;
    floorSave: string;
    floorDelete: string;
    pinList: string;
    pinFind: string;
    pinSave: string;
    pinDelete: string;
  };
}

export interface FloorListResponse {
  floors: Floor[];
  canEdit: boolean;
}
