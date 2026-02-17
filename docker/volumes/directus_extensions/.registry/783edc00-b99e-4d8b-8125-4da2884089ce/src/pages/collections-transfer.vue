<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import {
  readCollections,
  readActivities,
  deleteItems as deleteItemsSdk,
  readCollection,
  createCollection,
  readItems,
} from "@directus/sdk";
import { useStores } from "@directus/extensions-sdk";
import { sortBy, uniqBy, values } from "lodash";
import { format } from "date-fns";

import {
  useDirectusClients,
  useExportImport,
  useSchema,
  useActivities,
} from "../hooks";
import { nav } from "../path";
import Nav from "../components/nav.vue";
import DirectusClientSwitch from "../components/DirectusClientSwitch.vue";
import {
  splitCollections,
  removeFields,
  createJsonFile,
  createLogMessage,
  logMessageTypes,
  getPublicURL,
} from "../utils";
import {
  COLLECTION_HEADER,
  EXPORT_DATE_COLLECTOIN_NAME,
  EXPORT_DATE_COLLECTOIN_PARAMS,
  LAST_SYNC_HEADER,
  SHOW_CHANGED_ITEM_HEADER,
  COLLECTION_PER_REQUEST,
  ACTIVITIES_PER_REQUEST,
} from "../const";
import { CHANGED_ITEMS_ACTIVITIES } from "../path";

import type {
  Collection,
  ExportSchemaConfig,
  Activity,
  ActivitiesMap,
  ExportElement,
  ExportDateCollection,
  CollectionsRecord,
} from "../types";

const { useNotificationsStore } = useStores();
const notificationsStore = useNotificationsStore();

const loading = ref(true);
const transferProcessing = ref(false);
const tableLoading = ref(true);
const log = ref("");
const step = ref(0);
const clientsData = ref<DirectusClients | null>(null);
const userCollections = ref<Collection[]>([]);
const systemCollections = ref<Collection[]>([]);

const schema = ref<Awaited<ReturnType<typeof useSchema>> | null>(null);

const collectionsRecord = ref<CollectionsRecord | null>(null);

const activities = ref<ActivitiesMap>(new Map());
const activitiesDeletedItems = ref<ActivitiesMap>(new Map());
const activitiesCollectionSelections = ref<any[][]>([]);
const activitiesWereLoaded = ref(false);

const search = ref<string | null>(null);
const collectionsSorted = computed(() => {
  const normalizedSearch = search.value?.toLowerCase();
  const result = sortBy(
    userCollections.value.filter(
      (collection) =>
        collection.meta.export_schema?.export &&
        collection.collection.toLowerCase().includes(normalizedSearch ?? "")
    ),
    ["meta.sort", "collection"]
  );

  return result;
});
const selected = ref<Collection[]>([]);
const collectionForConfirm = ref<Collection[]>([]);
watch(selected, (collections) => {
  if (step.value === 0) {
    collectionForConfirm.value = collections;
  }
});

// this checkbox changes the export behavior: if we expand a collection, we want to export the collections deliberately, and we turn off ordered export
const expandSelectedCollection = ref(false);
function showCollectionForConfirm() {
  if (!expandSelectedCollection.value) return collectionForConfirm.value;

  const tmpCollections: Collection[] = [];
  for (let item of collectionForConfirm.value) {
    console.log(
      collectionsRecord.value,
      collectionsRecord.value[item.collection]
    );
    if (collectionsRecord.value?.[item.collection]) {
      for (let element of collectionsRecord.value[item.collection]
        ?.exportOrder || []) {
        tmpCollections.push(collectionsRecord.value[element.collection]);
      }
    }
  }
  return tmpCollections;
}

const { uploadFile, exportData } = useExportImport();

const init = async () => {
  clientsData.value = await useDirectusClients();
  await checkExportDateCollection();
  await requestInitData();
};

async function checkExportDateCollection() {
  try {
    const exportDateCollection = await clientsData.value.clientB.request(
      readCollection(EXPORT_DATE_COLLECTOIN_NAME)
    );
  } catch (e) {
    console.log("extention did`t find ", EXPORT_DATE_COLLECTOIN_NAME);
    console.log("creating...");
    await clientsData.value.clientB.request(
      createCollection(EXPORT_DATE_COLLECTOIN_PARAMS)
    );
    console.log(EXPORT_DATE_COLLECTOIN_NAME, " added");
  }
}

async function loadActivities() {
  loading.value = true;
  activities.value = new Map();
  activitiesDeletedItems.value = new Map();

  const { getActivities } = useActivities(clientsData.value.clientA);
  const getFilter =
    (actions: string[], part: number = 0) =>
    (page: number = 1) => {
      const filterObj = {
        limit: ACTIVITIES_PER_REQUEST,
        page,
        filter: {
          _and: [
            {
              action: {
                _in: actions,
              },
            },
            { _or: activitiesCollectionSelections.value[part] },
          ],
        } as any,
      };

      return filterObj;
    };

  console.log("activities loading...");
  for (let j = 0; j < activitiesCollectionSelections.value.length; j++) {
    activities.value = await getActivities(
      getFilter(["create", "update", "delete"], j),
      activities.value
    );
    activitiesDeletedItems.value = await getActivities(
      getFilter(["delete"], j),
      activitiesDeletedItems.value
    );
  }
  activitiesWereLoaded.value = true;

  console.log("activities detected: ", activities.value);
  console.log("deleted activities: ", activitiesDeletedItems.value);

  loading.value = false;
}

async function requestInitData() {
  loading.value = true;
  tableLoading.value = true;

  console.log("=== initial data loading ===");
  // getting collections from current Directus Client
  console.log("loading current Directus collections...");
  const rawCollections = await clientsData.value.clientA.request(
    readCollections()
  );
  const collections = splitCollections(rawCollections);
  userCollections.value = collections.userCollections;
  console.log("current Directus userCollections: ", userCollections.value);
  systemCollections.value = collections.systemCollections;

  // getting schema and sort it to collections, fields and relations
  console.log("parsing current schema...");
  schema.value = await useSchema(
    clientsData.value.clientA,
    clientsData.value.clientB
  );
  collectionsRecord.value = schema.value.collectionsRecord;
  console.log("collectionsRecord from schema:", collectionsRecord.value);

  // calc export order
  console.log("calculating export order...");
  for (let item of Object.values(collectionsRecord.value)) {
    item.meta.export_schema?.export && calcExportOrder(item);
  }

  const remoteExportDateCollection: ExportDateCollection[] =
    await clientsData.value.clientB.request(
      readItems<any, any, any>(EXPORT_DATE_COLLECTOIN_NAME, {
        limit: -1,
      })
    );

  let tmpCollectionSelections: any[] = [];
  console.log("remoteExportDateCollection", remoteExportDateCollection);
  // enrich data with last_sync_date and prepare collections for filter
  for (let element of remoteExportDateCollection) {
    if (element.last_sync_date) {
      if (collectionsRecord.value[element.id]) {
        collectionsRecord.value[element.id] = {
          ...collectionsRecord.value[element.id],
          last_sync_date: element.last_sync_date as unknown as string,
        };
      }
    }
  }

  // add last_sync_date to displaed items
  userCollections.value = userCollections.value.map((collection) => {
    if (collectionsRecord.value?.[collection.collection]?.last_sync_date) {
      return {
        ...collection,
        last_sync_date:
          collectionsRecord.value[collection.collection].last_sync_date,
      };
    }

    return collection;
  });

  // preparing filter for activities load
  for (let element of Object.values(collectionsRecord.value)) {
    const tmpFilter: any = {
      _and: [
        {
          collection: {
            _eq: element.collection,
          },
        },
      ],
    };
    if (element.last_sync_date) {
      tmpFilter._and.push({
        timestamp: {
          _gte: element.last_sync_date,
        },
      });
    }
    tmpCollectionSelections.push(tmpFilter);
    // divide into groups
    if (tmpCollectionSelections.length >= COLLECTION_PER_REQUEST) {
      activitiesCollectionSelections.value.push(tmpCollectionSelections);
      tmpCollectionSelections = [];
    }
  }

  if (tmpCollectionSelections.length)
    activitiesCollectionSelections.value.push(tmpCollectionSelections);

  loading.value = false;
  tableLoading.value = false;
}
onMounted(() => {
  init();
});

async function uploadItems(collectionName: string, data: JSON) {
  const file = createJsonFile(data, collectionName);
  await uploadFile(collectionName, file, clientsData.value.clientB);
}

async function deleteItems(collectionName: string) {
  if (!activitiesDeletedItems.value.has(collectionName)) return;
  const deletedItems = activitiesDeletedItems.value.get(collectionName)!;
  const allItems = activities.value.get(collectionName)!;
  const arrToDelete: string[] = [];
  for (let key of deletedItems.keys()) {
    if (allItems.has(key)) {
      const deleteActions = deletedItems.get(key)!;
      const deleteActionsDate = Object.keys(deleteActions);
      const deletionDate = deleteActionsDate[deleteActionsDate.length - 1];
      const actionsDate = Object.keys(allItems.get(key)!);
      if (actionsDate[actionsDate.length - 1] === deletionDate) {
        arrToDelete.push(key);
      }
    }
  }

  console.log(`"${collectionName}" items to delete:`, arrToDelete);

  //@ts-ignore
  await clientsData.value.clientB.request(
    deleteItemsSdk(collectionName, arrToDelete)
  );
}

const handleExportClick = ref(async () => {
  transferProcessing.value = true;
  const failedCollections = new Map();
  const textLog: string[] = [];
  const dataMap = new Map();
  const dateSyncJson: Partial<ExportDateCollection>[] = [];
  const date = new Date();
  console.log("transferring data...");

  // load data from master directus
  for (let item of selected.value) {
    console.log("---");
    console.log(`| Preparing "${item.collection}"...`, item);
    const iterableCollections = expandSelectedCollection.value
      ? [item]
      : collectionsRecord.value?.[item.collection]?.exportOrder;
    // download data per collection from exportOrder
    for (let element of iterableCollections || []) {
      let originData;
      try {
        originData = await exportData(element.collection);
        if (originData.errors) {
          // in this case directus doesn't pass error
          const e = new Error();
          //@ts-ignore
          e.errors = originData.errors;
          throw e;
        }
      } catch (e) {
        console.error(e);
        textLog.push(
          createLogMessage(
            logMessageTypes.error,
            `originData "${element.collection}" load faild ${
              e?.errors?.[0]?.message || ""
            }`
          )
        );
        continue;
      }

      console.log("| collection: ", element.collection);
      originData = removeFields(originData, ["user_created", "user_updated"]);
      const fieldsToRemove = element?.exclude_fields || [];
      console.log("|    fields to remove before export: ", fieldsToRemove);

      const secureData = removeFields(originData, fieldsToRemove);
      console.log("|    secure data: ", secureData);

      dataMap.set(element.collection, {
        origin: originData,
        secure: secureData,
      });
    }
    console.log("---");
  }

  // send data to receaving directus
  for (let [collectionName, data] of dataMap.entries()) {
    // dont transfer empty collection, if no data in collection directus throw error
    if (!data.secure.length) continue;
    try {
      console.log(`"${collectionName}" uploading...`);
      await uploadItems(collectionName, data.secure);
      console.log(`"${collectionName}" deleting...`);
      await deleteItems(collectionName);
      dateSyncJson.push({
        id: collectionName,
        data_update_date: date,
        last_sync_date: date,
      });
    } catch (e) {
      failedCollections.set(collectionName, false);
      textLog.push(
        createLogMessage(
          logMessageTypes.error,
          `${collectionName} load faild ${e?.errors?.[0]?.message}`
        )
      );
    }
  }

  // print log
  log.value = textLog.join("");

  // Update sync date of all unchanged collections
  Object.keys(collectionsRecord.value).map((collectionName) => {
    if (activitiesWereLoaded.value && !activities.value.has(collectionName)) {
      dateSyncJson.push({
        id: collectionName,
        last_sync_date: date,
      });
    }
  });

  if (dateSyncJson.length) {
    console.log(`updating "${EXPORT_DATE_COLLECTOIN_NAME}"...`, dateSyncJson);
    // update EXPORT_DATE_COLLECTOIN_NAME with new Dates
    await uploadItems(
      EXPORT_DATE_COLLECTOIN_NAME,
      dateSyncJson as unknown as JSON
    );
  }
  if (failedCollections.size === 0) {
    notificationsStore.add({
      type: "success",
      title: "Item successfully transferred",
      dialog: true,
    });
  }

  transferProcessing.value = false;
});

function resetData() {
  selected.value = [];
}

function updateModelValue(newValue) {
  const selectableTable =
    step.value === 0 ? collectionsSorted.value : collectionForConfirm.value;
  if (newValue.length === 0) {
    selected.value = newValue;
  } else if (newValue.length === selectableTable.length) {
    selected.value = [];
    for (let element of newValue) {
      selectCollection(element);
    }
  }
}

function traversalExportTree(
  collectionName: string,
  config: ExportElement,
  order: ExportElement[] = []
): ExportElement[] {
  for (let element of config?.referenced_collections || []) {
    order.push(...traversalExportTree(element.collection, element));
  }
  order.push({
    collection: collectionName,
    exclude_fields: config?.exclude_fields || [],
  });
  for (let element of config?.related_collections || []) {
    order.push(...traversalExportTree(element.collection, element));
  }

  return order;
}

function calcExportOrder(item: Collection) {
  if (
    collectionsRecord.value &&
    collectionsRecord.value?.[item.collection]?.exportOrder
  ) {
    return;
  }

  console.log("---");
  console.log(`| calculating export order for ${item.collection}...`);
  const exportOrder = traversalExportTree(
    item.collection,
    item.meta.export_schema!
  );
  collectionsRecord.value[item.collection].exportOrder = exportOrder;
  console.log("| schema: ", item.meta.export_schema);
  console.log(
    "| order: ",
    collectionsRecord.value?.[item.collection].exportOrder
  );
  console.log("---");
}

function selectCollection(item: Collection) {
  selected.value = uniqBy([...selected.value, item], "collection");
}

function handleItemSelect(event: { value: boolean; item: Collection }) {
  if (event.value) {
    selectCollection(event.item);
  } else {
    const tmp = [...selected.value].filter((value) => {
      return value.collection !== event.item.collection;
    });

    selected.value = tmp;
  }
}

function handleTransferClick() {
  step.value = 1;
  log.value = "";
}
function handleReturnToSelectionClick() {
  step.value = 0;
}

function handleLoadNewDataClick() {
  // Update initial data
  requestInitData();
  resetData();
}

const handleApplySchemaClick = ref(async () => {
  if (schema.value) {
    try {
      await schema.value.applySchema();

      notificationsStore.add({
        type: "success",
        title: "Change applied successfully",
        dialog: false,
      });
      schema.value.canApply = true;
    } catch (e) {
      console.error(e);
      notificationsStore.add({
        type: "error",
        title: "Error duiring apply",
        text: e?.errors?.[0]?.message,
        dialog: true,
      });
    }
  } else {
    throw new Error("apply schema before init data");
  }
});

function handleSelectChangedCollectionsClick() {
  for (let item of Object.values(collectionsRecord.value)) {
    if (item && item.meta.export_schema?.export && item.changed) {
      selectCollection(item);
    }
  }
}

function checkChangedCollection(name: string) {
  const collection = collectionsRecord.value?.[name] as Collection;
  return collection.changed;
}

function updateCollectionsRecordWithActivities() {
  for (let collection of Object.values(collectionsRecord.value)) {
    if (!collection.meta.export_schema?.export) continue;
    let collectionWasChanged = false;
    for (let item of collection.exportOrder) {
      if (activities.value.has(item.collection)) {
        collectionWasChanged = true;
        collectionsRecord.value[item.collection].changed = true;
      }
    }
    collectionsRecord.value[collection.collection].changed =
      collectionWasChanged;
  }
}

async function handleActivitiesLoad() {
  await loadActivities();
  updateCollectionsRecordWithActivities();
}

function handleClientChange(clientId: string) {
  requestInitData();
}
</script>

<template>
  <private-view title="Collection transfer">
    <template v-slot:navigation>
      <Nav :activeItem="nav['collection-transfer'].id" />
    </template>
    <DirectusClientSwitch
      v-if="clientsData"
      v-bind="clientsData"
      @client-change="handleClientChange"
    />
    <div class="wrapper">
      <div>
        <div class="mb controls">
          <v-input
            v-model="search"
            class="searchInput"
            :autofocus="userCollections.length > 25"
            type="search"
            :placeholder="'Search collection'"
            :full-width="false"
            v-if="step === 0"
          >
            <template #prepend>
              <v-icon name="search" outline />
            </template>
            <template #append>
              <v-icon
                v-if="search"
                clickable
                class="clear"
                name="close"
                @click.stop="search = null"
              />
            </template>
          </v-input>
          <v-button
            @click="handleReturnToSelectionClick"
            v-if="step === 1"
            :loading="loading || clientsData.loading"
            >Return to selection</v-button
          >
          <v-button
            @click="handleTransferClick"
            v-if="step === 0"
            :disabled="!selected.length"
            :loading="loading || clientsData.loading"
            >Transfer collections</v-button
          >
          <v-button
            @click="handleExportClick"
            :loading="transferProcessing"
            v-if="step === 1"
            :disabled="!selected.length"
            >Confirm Transfer</v-button
          >
          <v-checkbox
            v-if="step === 1"
            v-model="expandSelectedCollection"
            label="Expand selected collection"
          />
        </div>
        <div class="mb controls" v-if="step === 0">
          <v-button
            @click="handleLoadNewDataClick"
            :loading="loading || clientsData.loading"
            >Reload data</v-button
          >
          <v-button
            @click="handleActivitiesLoad"
            :loading="loading || clientsData.loading"
            >Load activities</v-button
          >
          <v-button
            @click="handleApplySchemaClick"
            :disabled="!schema?.canApply"
            >Transfer schema</v-button
          >
          <v-button
            @click="handleSelectChangedCollectionsClick"
            :loading="loading || clientsData.loading"
          >
            Select changed collections
          </v-button>
        </div>
        <v-textarea
          class="mb32"
          v-model="log"
          :disabled="true"
          v-if="step === 1 && log.length"
        />
        <h1 v-if="step === 1">Confirm collections</h1>
        <VTable
          :showSelect="'multiple'"
          :headers="[
            ...COLLECTION_HEADER,
            LAST_SYNC_HEADER,
            SHOW_CHANGED_ITEM_HEADER,
          ]"
          :items="step === 0 ? collectionsSorted : showCollectionForConfirm()"
          :itemKey="'collection'"
          :modelValue="selected"
          :loading="tableLoading || transferProcessing || clientsData.loading"
          @item-selected="handleItemSelect"
          @update:modelValue="updateModelValue"
        >
          <template #[`item.collection`]="{ item }">
            <span
              :class="{
                changedCollection: checkChangedCollection(item.collection),
              }"
              >{{ item.collection && item.collection }}</span
            >
          </template>
          <template #[`item.last_sync_date`]="{ item }">
            {{
              item.last_sync_date &&
              format(item.last_sync_date, "dd.MM.yyyy HH:mm:ss")
            }}
          </template>
          <template #[`item.changed_items_link`]="{ item }">
            <a
              class="link"
              v-if="checkChangedCollection(item.collection)"
              target="_blank"
              :href="`${getPublicURL()}admin/${CHANGED_ITEMS_ACTIVITIES}?collection=${
                item.collection
              }${item.last_sync_date ? `&date=${item.last_sync_date}` : ''}`"
            >
              open "{{ item.collection && item.collection }}" changed items
            </a>
          </template>
        </VTable>
      </div>
    </div>
  </private-view>
</template>

<style lang="scss" scoped>
.wrapper {
  margin: 24px 32px;
}

.mb {
  margin-bottom: 32px;
}

.searchInput {
  width: 100%;
}
.controls {
  display: flex;
  gap: 32px;
}

.link {
  text-decoration: underline;
  cursor: pointer;
}

.changedCollection {
  font-weight: 800;
  color: green;
}
</style>
