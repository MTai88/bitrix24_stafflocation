<template>
  <div
    ref="viewport"
    class="mtai-sl-viewport"
    :class="{ 'mtai-sl-viewport--grabbing': panning }"
    @pointerdown="onPanStart"
  >
    <div
      ref="stage"
      class="mtai-sl-stage"
      :style="stageStyle"
    >
      <img
        v-if="mapUrl"
        ref="image"
        class="mtai-sl-map"
        :src="mapUrl"
        alt=""
        draggable="false"
        @load="onImageLoad"
        @error="onImageError"
      >
      <button
        v-for="pin in pins"
        :key="pin.userId"
        :ref="(el) => setPinRef(pin.userId, el)"
        type="button"
        class="mtai-sl-pin"
        :class="{ 'mtai-sl-pin--edit': editing }"
        :style="pinStyle(pin)"
        :title="pin.name"
        @pointerdown.stop="onPinPointerDown(pin, $event)"
        @click.stop="onPinClick(pin, $event)"
      >
        <img
          class="mtai-sl-pin__avatar"
          :src="pin.avatar || defaultAvatar"
          :alt="pin.name"
          draggable="false"
        >
        <span class="mtai-sl-pin__stem" />
        <span class="mtai-sl-pin__tip" />
        <span
          v-if="editing"
          class="mtai-sl-pin__remove"
          title="Убрать с карты"
          @pointerdown.stop
          @click.stop="emit('remove', pin.userId)"
        >×</span>
      </button>
    </div>

    <div
      v-if="!loaded"
      class="mtai-sl-preloader"
    >
      <span class="mtai-sl-spinner" />
      <span>{{ errorText || 'Загрузка плана…' }}</span>
    </div>

    <label
      v-if="loaded"
      class="mtai-sl-zoom"
      @pointerdown.stop
    >
      <span class="mtai-sl-zoom__value">{{ percent }}%</span>
      <input
        class="mtai-sl-zoom__range"
        type="range"
        min="100"
        max="400"
        step="1"
        :value="percent"
        aria-label="Масштаб карты"
        @input="onSlider"
      >
    </label>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Focus, Pin } from '../js/types';

const props = defineProps<{
  mapUrl: string;
  pins: Pin[];
  editing: boolean;
  focus: Focus | null;
  defaultAvatar: string;
}>();

const emit = defineEmits<{
  move: [payload: { userId: number; left: number; top: number }];
  remove: [userId: number];
  open: [payload: { userId: number; element: HTMLElement }];
}>();

const MIN_SCALE = 1;
const MAX_SCALE = 4;
const FOCUS_SCALE = 2.5;

const viewport = ref<HTMLElement | null>(null);
const stage = ref<HTMLElement | null>(null);
const image = ref<HTMLImageElement | null>(null);

const loaded = ref(false);
const errorText = ref('');
const scale = ref(1);
const translateX = ref(0);
const translateY = ref(0);
const stageWidth = ref(0);
const stageHeight = ref(0);
const panning = ref(false);

const drag = ref<{ userId: number; left: number; top: number } | null>(null);
const focusOpened = ref(false);
const pinEls = new Map<number, HTMLElement>();

let panOrigin = { x: 0, y: 0, tx: 0, ty: 0 };
let pinGesture: { userId: number; x: number; y: number; moved: boolean; pointerId: number } | null = null;
let pinDragged = false;

const percent = computed(() => Math.round(scale.value * 100));

const stageStyle = computed(() => ({
  width: stageWidth.value ? `${stageWidth.value}px` : '0px',
  height: stageHeight.value ? `${stageHeight.value}px` : '0px',
  transform: `translate(${translateX.value}px, ${translateY.value}px) scale(${scale.value})`,
}));

watch(() => props.mapUrl, () => {
  loaded.value = false;
  errorText.value = '';
  focusOpened.value = false;
  scale.value = 1;
  translateX.value = 0;
  translateY.value = 0;
});

watch(() => props.pins, () => {
  void tryFocus();
});

watch(() => props.focus?.token ?? 0, (token, previous) => {
  if (!token || token === previous) {
    return;
  }
  focusOpened.value = false;
  void tryFocus();
});

function setPinRef(userId: number, el: unknown): void {
  if (el instanceof HTMLElement) {
    pinEls.set(userId, el);
  } else {
    pinEls.delete(userId);
  }
}

function pinStyle(pin: Pin): Record<string, string> {
  const counter = scale.value > 0 ? 1 / scale.value : 1;
  return {
    left: `${displayLeft(pin)}%`,
    top: `${displayTop(pin)}%`,
    transform: `translate(-50%, -100%) scale(${counter})`,
  };
}

function displayLeft(pin: Pin): number {
  return drag.value?.userId === pin.userId ? drag.value.left : pin.left;
}

function displayTop(pin: Pin): number {
  return drag.value?.userId === pin.userId ? drag.value.top : pin.top;
}

function clampScale(value: number): number {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));
}

function fit(): void {
  const vp = viewport.value;
  const img = image.value;
  if (!vp || !img || !img.naturalWidth || !img.naturalHeight) {
    return;
  }

  const ratio = img.naturalWidth / img.naturalHeight;
  let width = vp.clientWidth;
  let height = width / ratio;
  if (height > vp.clientHeight) {
    height = vp.clientHeight;
    width = height * ratio;
  }

  stageWidth.value = width;
  stageHeight.value = height;
  scale.value = 1;
  translateX.value = (vp.clientWidth - width) / 2;
  translateY.value = (vp.clientHeight - height) / 2;
}

function centerOn(leftPct: number, topPct: number): void {
  const vp = viewport.value;
  if (!vp || !stageWidth.value || !stageHeight.value) {
    return;
  }
  const px = (leftPct / 100) * stageWidth.value;
  const py = (topPct / 100) * stageHeight.value;
  translateX.value = vp.clientWidth / 2 - px * scale.value;
  translateY.value = vp.clientHeight / 2 - py * scale.value;
}

function zoomAt(nextScale: number, clientX: number, clientY: number): void {
  const vp = viewport.value;
  if (!vp) {
    return;
  }
  const clamped = clampScale(nextScale);
  const rect = vp.getBoundingClientRect();
  const vx = clientX - rect.left;
  const vy = clientY - rect.top;
  const sx = (vx - translateX.value) / scale.value;
  const sy = (vy - translateY.value) / scale.value;
  scale.value = clamped;
  translateX.value = vx - sx * clamped;
  translateY.value = vy - sy * clamped;
}

function zoomAtCenter(nextScale: number): void {
  const vp = viewport.value;
  if (!vp) {
    return;
  }
  const rect = vp.getBoundingClientRect();
  zoomAt(nextScale, rect.left + rect.width / 2, rect.top + rect.height / 2);
}

function onSlider(event: Event): void {
  const value = Number((event.target as HTMLInputElement).value);
  zoomAtCenter(value / 100);
}

function onWheel(event: WheelEvent): void {
  if (!loaded.value) {
    return;
  }
  event.preventDefault();
  const factor = event.deltaY < 0 ? 1.12 : 1 / 1.12;
  zoomAt(scale.value * factor, event.clientX, event.clientY);
}

async function tryFocus(): Promise<void> {
  const focus = props.focus;
  if (!loaded.value || !focus || focusOpened.value) {
    return;
  }
  await nextTick();
  const element = pinEls.get(focus.userId);
  if (!element) {
    return;
  }
  focusOpened.value = true;
  scale.value = FOCUS_SCALE;
  centerOn(focus.left, focus.top);
  if (!props.editing && focus.openProfile !== false) {
    emit('open', { userId: focus.userId, element });
  }
}

function onImageLoad(): void {
  errorText.value = '';
  fit();
  loaded.value = true;
  void tryFocus();
}

function onImageError(): void {
  errorText.value = 'Не удалось загрузить план этажа';
  loaded.value = false;
}

function onPanStart(event: PointerEvent): void {
  if (!loaded.value || event.button !== 0) {
    return;
  }
  const target = event.target as HTMLElement | null;
  if (target?.closest('.mtai-sl-pin, .mtai-sl-zoom')) {
    return;
  }
  panning.value = true;
  panOrigin = { x: event.clientX, y: event.clientY, tx: translateX.value, ty: translateY.value };
  viewport.value?.setPointerCapture(event.pointerId);
}

function onPanMove(event: PointerEvent): void {
  if (!panning.value) {
    return;
  }
  translateX.value = panOrigin.tx + (event.clientX - panOrigin.x);
  translateY.value = panOrigin.ty + (event.clientY - panOrigin.y);
}

function onPanEnd(event: PointerEvent): void {
  if (!panning.value) {
    return;
  }
  panning.value = false;
  if (viewport.value?.hasPointerCapture(event.pointerId)) {
    viewport.value.releasePointerCapture(event.pointerId);
  }
}

function pointToPercent(clientX: number, clientY: number): { left: number; top: number } {
  const rect = stage.value?.getBoundingClientRect();
  if (!rect || rect.width === 0 || rect.height === 0) {
    return { left: 50, top: 50 };
  }
  const left = ((clientX - rect.left) / rect.width) * 100;
  const top = ((clientY - rect.top) / rect.height) * 100;
  return {
    left: Math.min(100, Math.max(0, left)),
    top: Math.min(100, Math.max(0, top)),
  };
}

function onPinPointerDown(pin: Pin, event: PointerEvent): void {
  if (event.button !== 0) {
    return;
  }
  pinGesture = {
    userId: pin.userId,
    x: event.clientX,
    y: event.clientY,
    moved: false,
    pointerId: event.pointerId,
  };
  (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onPinPointerMove(event: PointerEvent): void {
  if (!pinGesture || pinGesture.pointerId !== event.pointerId) {
    return;
  }
  const dx = event.clientX - pinGesture.x;
  const dy = event.clientY - pinGesture.y;
  if (!pinGesture.moved && Math.hypot(dx, dy) < 4) {
    return;
  }
  pinGesture.moved = true;
  if (!props.editing) {
    return;
  }
  const point = pointToPercent(event.clientX, event.clientY);
  drag.value = { userId: pinGesture.userId, left: point.left, top: point.top };
}

function onPinPointerUp(event: PointerEvent): void {
  if (!pinGesture || pinGesture.pointerId !== event.pointerId) {
    return;
  }
  const gesture = pinGesture;
  pinGesture = null;
  pinDragged = gesture.moved;

  if (props.editing && gesture.moved && drag.value?.userId === gesture.userId) {
    emit('move', { userId: gesture.userId, left: drag.value.left, top: drag.value.top });
    drag.value = null;
    return;
  }
  drag.value = null;
}

function onPinClick(pin: Pin, event: MouseEvent): void {
  // Штатный мини-профиль вешает на bindElement click и по нему сразу закрывается.
  // Этот клик и есть открытие, поэтому он не должен дойти до обработчика профиля.
  event.stopImmediatePropagation();
  if (pinDragged || props.editing) {
    return;
  }
  const element = event.currentTarget;
  if (element instanceof HTMLElement) {
    emit('open', { userId: pin.userId, element });
  }
}

function viewCenterPercent(): { left: number; top: number } {
  const vp = viewport.value;
  if (!vp) {
    return { left: 50, top: 50 };
  }
  const rect = vp.getBoundingClientRect();
  return pointToPercent(rect.left + rect.width / 2, rect.top + rect.height / 2);
}

defineExpose({ viewCenterPercent });

function onWindowPointerMove(event: PointerEvent): void {
  onPanMove(event);
  onPinPointerMove(event);
}

function onWindowPointerUp(event: PointerEvent): void {
  onPanEnd(event);
  onPinPointerUp(event);
}

onMounted(() => {
  viewport.value?.addEventListener('wheel', onWheel, { passive: false });
  window.addEventListener('pointermove', onWindowPointerMove);
  window.addEventListener('pointerup', onWindowPointerUp);
  window.addEventListener('pointercancel', onWindowPointerUp);
});

onBeforeUnmount(() => {
  viewport.value?.removeEventListener('wheel', onWheel);
  window.removeEventListener('pointermove', onWindowPointerMove);
  window.removeEventListener('pointerup', onWindowPointerUp);
  window.removeEventListener('pointercancel', onWindowPointerUp);
});
</script>

<style scoped>
.mtai-sl-viewport {
  position: relative;
  overflow: hidden;
  height: calc(100vh - 220px);
  min-height: 420px;
  background: #eef2f4;
  border: 1px solid #d5dde3;
  border-radius: 8px;
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.mtai-sl-viewport--grabbing {
  cursor: grabbing;
}

.mtai-sl-stage {
  position: absolute;
  top: 0;
  left: 0;
  transform-origin: 0 0;
}

.mtai-sl-map {
  display: block;
  width: 100%;
  height: 100%;
  pointer-events: none;
  user-select: none;
}

.mtai-sl-pin {
  position: absolute;
  width: 44px;
  height: 58px;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  transform-origin: 50% 100%;
  cursor: pointer;
}

.mtai-sl-pin--edit {
  cursor: grab;
}

.mtai-sl-pin__avatar {
  display: block;
  width: 40px;
  height: 40px;
  margin: 0 auto;
  border: 2px solid #fff;
  border-radius: 50%;
  object-fit: cover;
  background: #dfe3e6;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
  pointer-events: none;
}

.mtai-sl-pin__stem {
  display: block;
  width: 2px;
  height: 10px;
  margin: 0 auto;
  background: #2fc6f6;
}

.mtai-sl-pin__tip {
  display: block;
  width: 8px;
  height: 8px;
  margin: -4px auto 0;
  border-radius: 50%;
  background: #2fc6f6;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.mtai-sl-pin__remove {
  position: absolute;
  top: -4px;
  right: -2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #e45757;
  color: #fff;
  font-size: 14px;
  line-height: 16px;
  text-align: center;
}

.mtai-sl-preloader {
  position: absolute;
  inset: 0;
  z-index: 3;
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: center;
  justify-content: center;
  background: rgba(246, 248, 249, 0.9);
  color: #525c69;
  font-size: 14px;
}

.mtai-sl-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid #d5dde3;
  border-top-color: #2fc6f6;
  border-radius: 50%;
  animation: mtai-sl-spin 0.8s linear infinite;
}

.mtai-sl-zoom {
  position: absolute;
  right: 16px;
  bottom: 16px;
  z-index: 4;
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  cursor: default;
}

.mtai-sl-zoom__value {
  min-width: 42px;
  color: #333;
  font-size: 13px;
}

.mtai-sl-zoom__range {
  width: 160px;
  cursor: pointer;
}
</style>
