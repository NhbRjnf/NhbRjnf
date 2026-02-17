import {
  createDirectus,
  rest,
  authentication,
  RestClient,
  AuthenticationClient,
  DirectusClient,
} from "@directus/sdk";
import { useStores, useItems } from "@directus/extensions-sdk";
import { Ref, computed, ref } from "vue";

const dirOrder = ['stage', 'prod'] as const;

type ClientDirectus = DirectusClient<any> & AuthenticationClient<any> & RestClient<any>;
type ExportToolsRecord = {
  id: string;
  url: string;
  login: string;
  password: string;
  current_client: boolean;
}

export const useDirectusClients = async () => {
  const {
    useNotificationsStore,
  } = useStores();
  const notificationsStore = useNotificationsStore();
  const clientA = ref<ClientDirectus>();
  const clientB = ref<ClientDirectus>();
  const loading = ref(false);

  const envResult = useItems(ref("export_tools"), {
    limit: ref(-1),
    fields: ref(["*"]),
    filter: ref(null),
  });
  await envResult.getItems();

  let currentClientRecord = ref<ExportToolsRecord | null>(null);
  const envConfig = envResult.items.value.reduce((acc, val) => {
    if (val.current_client) {
      currentClientRecord.value = val as ExportToolsRecord;
    }
    acc[val.type] = {
      ...val,
    }

    return acc;
  }, {});
  const masterClient = ref<ExportToolsRecord>(currentClientRecord.value || envConfig[dirOrder[0]]);
  const clientRecords = computed(() => envResult.items.value.reduce((acc, item) => {
    if (item.id !== masterClient.value.id) {
      acc[item.id] = item;
    }
    return acc;
  }, {}));
  const remoteClient = ref<ExportToolsRecord>(clientRecords.value[localStorage.getItem('remoteClient') || 0] || envConfig[dirOrder[1]]);

  const createAuthorizedClient = async (record: ExportToolsRecord) => {
    const client = createDirectus(record.url)
      .with(authentication())
      .with(rest());
    await client.login(
      record.login,
      record.password
    );

    return client;
  }

  const saveClientSelect = (id: string) => {
    localStorage.setItem('remoteClient', id);
  }

  try {
    try {
      loading.value = true;
      clientA.value = await createAuthorizedClient(masterClient.value);
      clientB.value = await createAuthorizedClient(remoteClient.value);
      loading.value = false;
    } catch (e) {
      console.error(e);
    }
  } catch (e: any) {
    console.error(e);
    notificationsStore.add({
      type: "error",
      title: 'Login Error',
      text: e?.errors?.[0]?.message,
      dialog: true,
    });
  }

  const changeRemoteClient = async (clientId: string) => {
    loading.value = true;
    const newClient = clientRecords.value[clientId];
    try {
      const directusClient = await createAuthorizedClient(newClient);
      clientB.value = directusClient;
      saveClientSelect(clientId);
      loading.value = false;
    } catch (e) {
      console.error(e);
    }
  };

  return {
    loading,
    clientA, clientB,
    masterClient,
    remoteClient,
    clientRecords,
    createAuthorizedClient,
    saveClientSelect,
    changeRemoteClient,
  };
};

export type DirectusClients = {
  loading: Ref<boolean>;
  clientA: Ref<ClientDirectus | undefined>;
  clientB: Ref<ClientDirectus | undefined>;
  masterClient: ExportToolsRecord;
  remoteClient: ExportToolsRecord;
  clientRecords: Ref<ExportToolsRecord[]>;
  createAuthorizedClient: (record: ExportToolsRecord) => Promise<DirectusClient<any> & AuthenticationClient<any> & RestClient<any>>;
  saveClientSelect: (record: ExportToolsRecord) => void;
  changeRemoteClient: (clientId: string) => void;
}