interface MiniProfile {
  show(): void;
  close(): void;
  setBindElement(element: HTMLElement | null): void;
}

interface BitrixGlobal {
  BX?: {
    Runtime?: {
      loadExtension(name: string): Promise<unknown>;
    };
    Intranet?: {
      User?: {
        UserMiniProfileManager?: {
          create(options: { id: string; userId: number; bindElement: HTMLElement }): MiniProfile;
          getById(id: string): MiniProfile | undefined;
        };
      };
    };
    UI?: {
      EntitySelector?: {
        Dialog: new (options: Record<string, unknown>) => {
          show(): void;
          hide(): void;
        };
        TagSelector: new (options: Record<string, unknown>) => {
          renderTo(container: HTMLElement): void;
          removeTags(): void;
        };
      };
    };
  };
}

function bx(): BitrixGlobal['BX'] {
  return (window as unknown as BitrixGlobal).BX;
}

let openedId = '';
const profiles = new Set<MiniProfile>();

/** Закрыть мини-профили и снять показ по наведению, пока идёт редактирование. */
export function dismissMiniProfiles(): void {
  openedId = '';
  for (const profile of profiles) {
    profile.setBindElement(null);
  }
}

/** Штатный мини-профиль сотрудника Bitrix24. */
export async function openMiniProfile(userId: number, bindElement: HTMLElement): Promise<void> {
  const runtime = bx();
  if (!runtime?.Intranet?.User?.UserMiniProfileManager && runtime?.Runtime?.loadExtension) {
    await runtime.Runtime.loadExtension('intranet.user.mini-profile');
  }

  const manager = bx()?.Intranet?.User?.UserMiniProfileManager;
  if (!manager) {
    return;
  }

  if (openedId) {
    manager.getById(openedId)?.close();
  }

  const id = `mtai-stafflocation-${userId}`;
  openedId = id;
  const profile = manager.create({
    id,
    userId,
    bindElement,
  });
  profiles.add(profile);
  profile.show();
}

const userOptions = {
  inviteEmployeeLink: false,
  intranetUsersOnly: true,
  emailUsers: false,
};

function employeeEntities(): Record<string, unknown>[] {
  return [
    {
      id: 'user',
      options: userOptions,
    },
    {
      id: 'department',
      options: {
        selectMode: 'usersOnly',
        fillRecentTab: false,
        userOptions,
      },
    },
  ];
}

function userIdFromItem(item: { getId(): string | number; getEntityId?: () => string }): number {
  if (item.getEntityId && item.getEntityId() !== 'user') {
    return 0;
  }
  const userId = Number(item.getId());
  return userId > 0 ? userId : 0;
}

/** Штатное поле: поиск сотрудника и выбор через структуру отделов. */
export function mountEmployeeFinder(container: HTMLElement, onSelect: (userId: number) => void): { clear(): void } | null {
  const TagSelector = bx()?.UI?.EntitySelector?.TagSelector;
  if (!TagSelector) {
    return null;
  }

  const selector = new TagSelector({
    id: 'mtai-stafflocation-finder',
    multiple: false,
    addButtonCaption: 'Найти',
    addButtonCaptionMore: 'Найти',
    textBoxWidth: '100%',
    placeholder: 'Найти сотрудника',
    dialogOptions: {
      id: 'mtai-stafflocation-finder-dialog',
      context: 'mtai-stafflocation-finder',
      enableSearch: true,
      hideOnSelect: true,
      entities: employeeEntities(),
    },
    events: {
      onTagAdd: (event: { getData(): { tag: { getId(): string | number; getEntityId(): string } } }) => {
        const userId = userIdFromItem(event.getData().tag);
        if (userId > 0) {
          onSelect(userId);
        }
      },
    },
  });
  selector.renderTo(container);

  return {
    clear(): void {
      selector.removeTags();
    },
  };
}

export function openUserSelector(target: HTMLElement, onSelect: (userId: number) => void): void {
  const Dialog = bx()?.UI?.EntitySelector?.Dialog;
  if (!Dialog) {
    return;
  }

  const dialog = new Dialog({
    id: 'mtai-stafflocation-users',
    targetNode: target,
    context: 'mtai-stafflocation',
    enableSearch: true,
    multiple: false,
    hideOnSelect: true,
    entities: employeeEntities(),
    events: {
      'Item:onSelect': (event: { getData(): { item: { getId(): string | number; getEntityId?: () => string } } }) => {
        const userId = userIdFromItem(event.getData().item);
        if (userId > 0) {
          onSelect(userId);
        }
      },
    },
  });
  dialog.show();
}
