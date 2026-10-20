<template>
  <div class="mtai-sl tw-flex tw-flex-col tw-gap-3">
    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
      <template v-if="showFloorPicker">
        <button
          v-for="floor in floors"
          :key="floor.id"
          type="button"
          class="mtai-sl-floor"
          :class="{ 'mtai-sl-floor--active': floor.id === selectedId }"
          @click="selectFloor(floor.id)"
        >
          {{ floor.name }}
        </button>
      </template>

      <button
        v-if="canEdit"
        type="button"
        class="mtai-sl-mode"
        @click="editing = !editing"
      >
        {{ editing ? 'Просмотр' : 'Редактирование' }}
      </button>

      <div
        ref="finderBox"
        class="mtai-sl-finder"
      />
    </div>

    <p
      v-if="error"
      class="mtai-sl-error"
    >
      {{ error }}
    </p>

    <div
      v-if="editing"
      class="mtai-sl-editor tw-flex tw-flex-wrap tw-items-end tw-gap-3"
    >
      <label class="mtai-sl-field">
        <span>Название этажа</span>
        <input
          v-model="draftName"
          type="text"
          maxlength="255"
        >
      </label>
      <label class="mtai-sl-field">
        <span>Порядок</span>
        <input
          v-model.number="draftSort"
          type="number"
          min="1"
        >
      </label>
      <label class="mtai-sl-field">
        <span>{{ selectedId ? 'Заменить карту' : 'Карта этажа' }}</span>
        <input
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp"
          @change="onFile"
        >
      </label>
      <button
        type="button"
        class="mtai-sl-mode"
        :disabled="busy"
        @click="saveFloor"
      >
        {{ selectedId ? 'Сохранить этаж' : 'Добавить этаж' }}
      </button>
      <button
        v-if="selectedId"
        type="button"
        class="mtai-sl-mode"
        :disabled="busy"
        @click="startNewFloor"
      >
        Новый этаж
      </button>
      <button
        v-if="selectedId"
        type="button"
        class="mtai-sl-danger"
        :disabled="busy"
        @click="removeFloor"
      >
        Удалить этаж
      </button>
      <button
        v-if="selectedId && selectedFloor?.mapUrl"
        type="button"
        class="mtai-sl-mode"
        :disabled="busy"
        @click="addEmployee"
      >
        Добавить сотрудника
      </button>
    </div>

    <FloorMap
      v-if="selectedFloor?.mapUrl"
      ref="mapRef"
      :map-url="selectedFloor.mapUrl"
      :pins="pins"
      :editing="editing"
      :focus="mapFocus"
      :default-avatar="config.defaultAvatar"
      @move="onMove"
      @remove="onRemove"
      @open="onOpen"
    />
    <div
      v-else
      class="mtai-sl-empty"
    >
      {{ emptyText }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import FloorMap from './FloorMap.vue';
import { Api, ApiError } from '../js/api';
import { dismissMiniProfiles, mountEmployeeFinder, openMiniProfile, openUserSelector } from '../js/bitrix';
import type { AppConfig, Floor, Focus, Pin } from '../js/types';

const props = defineProps<{ config: AppConfig }>();

const api = new Api(props.config);
const canEdit = props.config.canEdit;

const floors = ref<Floor[]>([]);
const pins = ref<Pin[]>([]);
const selectedId = ref(0);
const editing = ref(false);
const busy = ref(false);
const error = ref('');
const draftName = ref('');
const draftSort = ref(500);
const draftFile = ref<File | null>(null);
const focusPending = ref<Focus | null>(props.config.focus);
const mapRef = ref<{ viewCenterPercent(): { left: number; top: number } } | null>(null);
const finderBox = ref<HTMLElement | null>(null);
let finder: { clear(): void } | null = null;

const selectedFloor = computed(() => floors.value.find((floor) => floor.id === selectedId.value) ?? null);

const showFloorPicker = computed(() => editing.value || floors.value.length > 1);

watch(editing, (isEditing) => {
  if (isEditing) {
    dismissMiniProfiles();
  }
});

const mapFocus = computed(() => {
  const focus = focusPending.value;
  if (!focus || focus.floorId !== selectedId.value) {
    return null;
  }
  return focus;
});

const emptyText = computed(() => {
  if (!floors.value.length) {
    return canEdit
      ? 'Этажей пока нет. Включите редактирование и добавьте этаж с картой.'
      : 'План офиса ещё не опубликован.';
  }
  return 'У этажа нет карты.';
});

function rememberUser(userId: number): void {
  const url = new URL(window.location.href);
  url.searchParams.set('user', String(userId));
  window.history.replaceState(null, '', url);
}

function fillDraft(floor: Floor | null): void {
  draftName.value = floor?.name ?? '';
  draftSort.value = floor?.sort || 500;
  draftFile.value = null;
}

async function loadFloors(preferId = 0): Promise<void> {
  const data = await api.floorList();
  floors.value = data.floors;
  const focusId = focusPending.value?.floorId ?? 0;
  const next = preferId || focusId || selectedId.value || data.floors[0]?.id || 0;
  selectedId.value = data.floors.some((floor) => floor.id === next) ? next : (data.floors[0]?.id ?? 0);
  fillDraft(selectedFloor.value);
}

async function loadPins(): Promise<void> {
  if (!selectedId.value) {
    pins.value = [];
    return;
  }
  pins.value = await api.pinList(selectedId.value);
}

async function focusFoundEmployee(userId: number): Promise<void> {
  dismissMiniProfiles();
  error.value = '';
  let employee;
  try {
    employee = await api.pinFind(userId);
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось найти сотрудника';
    return;
  }
  if (!employee) {
    error.value = 'Сотрудник не отмечен на карте';
    finder?.clear();
    return;
  }
  focusPending.value = {
    userId: employee.userId,
    floorId: employee.floorId,
    left: employee.left,
    top: employee.top,
    token: Date.now(),
    openProfile: false,
  };
  if (selectedId.value === employee.floorId) {
    return;
  }
  selectedId.value = employee.floorId;
  fillDraft(selectedFloor.value);
  try {
    await loadPins();
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось загрузить сотрудников';
  }
}

async function selectFloor(id: number): Promise<void> {
  if (id === selectedId.value) {
    return;
  }
  focusPending.value = null;
  selectedId.value = id;
  fillDraft(selectedFloor.value);
  error.value = '';
  try {
    await loadPins();
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось загрузить сотрудников';
  }
}

function startNewFloor(): void {
  selectedId.value = 0;
  pins.value = [];
  fillDraft(null);
  focusPending.value = null;
}

function onFile(event: Event): void {
  const input = event.target as HTMLInputElement;
  draftFile.value = input.files?.[0] ?? null;
}

async function saveFloor(): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    const saved = await api.floorSave(selectedId.value, draftName.value.trim(), draftSort.value || 500, draftFile.value);
    await loadFloors(saved.id);
    await loadPins();
    draftFile.value = null;
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось сохранить этаж';
  } finally {
    busy.value = false;
  }
}

async function removeFloor(): Promise<void> {
  if (!selectedId.value || !window.confirm('Удалить этаж и всех сотрудников с его карты?')) {
    return;
  }
  busy.value = true;
  error.value = '';
  try {
    await api.floorDelete(selectedId.value);
    selectedId.value = 0;
    await loadFloors();
    await loadPins();
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось удалить этаж';
  } finally {
    busy.value = false;
  }
}

function addEmployee(event: MouseEvent): void {
  const button = event.currentTarget;
  if (!(button instanceof HTMLElement) || !selectedId.value) {
    return;
  }
  openUserSelector(button, (userId) => {
    const center = mapRef.value?.viewCenterPercent() ?? { left: 50, top: 50 };
    void placeEmployee(userId, center.left, center.top);
  });
}

async function placeEmployee(userId: number, left: number, top: number): Promise<void> {
  if (!selectedId.value) {
    return;
  }
  error.value = '';
  try {
    const pin = await api.pinSave(selectedId.value, userId, left, top);
    const rest = pins.value.filter((item) => item.userId !== pin.userId);
    pins.value = [...rest, pin];
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось поставить сотрудника';
  }
}

function onMove(payload: { userId: number; left: number; top: number }): void {
  const current = pins.value.find((pin) => pin.userId === payload.userId);
  if (current) {
    current.left = payload.left;
    current.top = payload.top;
  }
  void placeEmployee(payload.userId, payload.left, payload.top);
}

async function onRemove(userId: number): Promise<void> {
  if (!selectedId.value) {
    return;
  }
  error.value = '';
  try {
    await api.pinDelete(selectedId.value, userId);
    pins.value = pins.value.filter((pin) => pin.userId !== userId);
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось убрать сотрудника';
  }
}

function onOpen(payload: { userId: number; element: HTMLElement }): void {
  if (editing.value) {
    return;
  }
  focusPending.value = null;
  rememberUser(payload.userId);
  void openMiniProfile(payload.userId, payload.element);
}

onMounted(async () => {
  if (finderBox.value) {
    finder = mountEmployeeFinder(finderBox.value, (userId) => {
      void focusFoundEmployee(userId);
    });
  }
  try {
    await loadFloors();
    await loadPins();
  } catch (reason) {
    error.value = reason instanceof ApiError ? reason.message : 'Не удалось загрузить карту';
  }
});
</script>

<style scoped>
.mtai-sl-floor,
.mtai-sl-mode,
.mtai-sl-danger {
  border: 1px solid #c6cdd3;
  border-radius: 6px;
  background: #fff;
  color: #333;
  padding: 6px 12px;
  cursor: pointer;
  font-size: 14px;
}

.mtai-sl-floor--active,
.mtai-sl-mode {
  border-color: #2fc6f6;
  color: #2066b0;
}

.mtai-sl-danger {
  border-color: #e45757;
  color: #c23b3b;
}

.mtai-sl-floor:disabled,
.mtai-sl-mode:disabled,
.mtai-sl-danger:disabled {
  opacity: 0.6;
  cursor: default;
}

.mtai-sl-error {
  margin: 0;
  color: #c23b3b;
}

.mtai-sl-editor {
  padding: 12px;
  border: 1px solid #e3e8ec;
  border-radius: 8px;
  background: #fafbfc;
}

.mtai-sl-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #525c69;
  font-size: 13px;
}

.mtai-sl-field input[type='text'],
.mtai-sl-field input[type='number'] {
  min-width: 180px;
  padding: 6px 8px;
  border: 1px solid #c6cdd3;
  border-radius: 6px;
  color: #333;
}

.mtai-sl-finder {
  flex: 1 1 260px;
  max-width: 360px;
  margin-left: auto;
}

.mtai-sl-empty {
  padding: 48px 16px;
  border: 1px dashed #c6cdd3;
  border-radius: 8px;
  color: #525c69;
  text-align: center;
}
</style>
