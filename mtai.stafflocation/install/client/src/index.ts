/**
 * Точка входа карты расположения (mtai.stafflocation).
 *
 * Страница /company/location/ рендерит div#mtai-stafflocation-app.
 * Конфиг отдаёт PHP-компонент mtai:stafflocation.app.
 */
import { createApp } from 'vue';
import App from './vue/App.vue';
import './scss/main.css';

const mount = document.getElementById('mtai-stafflocation-app');

if (mount) {
  let config: Record<string, unknown> = {};
  try {
    config = JSON.parse(mount.dataset.config || '{}');
  } catch (error) {
    console.error('mtai.stafflocation: broken data-config', error);
  }

  createApp(App, { config }).mount(mount);
}
