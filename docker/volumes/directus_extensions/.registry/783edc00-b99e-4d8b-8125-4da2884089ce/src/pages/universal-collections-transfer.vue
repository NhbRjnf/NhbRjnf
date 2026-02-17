<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import {
  readItems,
  readCollections,
  schemaSnapshot,
  schemaDiff,
  schemaApply,
  utilsExport,
  SchemaSnapshotOutput,
  readRelationByCollection,
} from "@directus/sdk";
import { useStores } from "@directus/extensions-sdk";
import { clone, cloneDeep, sortBy, uniqBy } from "lodash";

import { useDirectusClients, useExportImport } from "../hooks";
import { nav } from "../path";
import Nav from "../components/nav.vue";
import {
  splitCollections,
  recordFromCollectionable,
  mapFromCollectionable,
  getRelationFields,
  removeFields,
  createJsonFile,
  createLogMessage,
  logMessageTypes,
} from "../utils";
import { COLLECTION_HEADER } from "../const";

import type { Collection } from "../types";

const { useNotificationsStore } = useStores();
const notificationsStore = useNotificationsStore();

const loading = ref(true);
const tableLoading = ref(true);
const withSystemCollection = ref(false);
const isCollectionSearchDeep = ref(true);
const log = ref("");
const step = ref(0);
const clientA = ref<any>(null);
const clientB = ref<any>(null);
const userCollections = ref<Collection[]>([]);
const systemCollections = ref<Collection[]>([]);
const schema = ref<SchemaSnapshotOutput | null>(null);

const collectionsMap = ref<any>(null);
const fieldsMap = ref<any>(null);
const relationsMap = ref<any>(null);

// const search = ref<string | null>(null);
const search = ref<string | null>("");
const collectionsSorted = computed(() => {
  const normalizedSearch = search.value?.toLowerCase();
  const result = sortBy(
    userCollections.value.filter(
      (collection) =>
        !collection.meta.hidden &&
        collection.collection.toLowerCase().includes(normalizedSearch ?? "")
    ),
    ["meta.sort", "collection"]
  );

  if (withSystemCollection.value) {
    result.push(...systemCollections.value);
  }

  return result;
});
const selected = ref<Collection[]>([]);
const collectionForConfirm = ref<Collection[]>([]);
watch(selected, (collections) => {
  if (step.value === 0) {
    collectionForConfirm.value = collections;
  }
});

const { uploading, importing, uploadFile, exportData } = useExportImport();

const init = async () => {
  const [client1, client2] = await useDirectusClients();
  clientA.value = client1;
  clientB.value = client2;

  const rawCollections = await client1.request(readCollections());
  const collections = splitCollections(rawCollections);
  schema.value = await client1.request(schemaSnapshot());

  userCollections.value = collections.userCollections;
  systemCollections.value = collections.systemCollections;
  collectionsMap.value = recordFromCollectionable(
    schema.value!.collections as any
  );
  fieldsMap.value = mapFromCollectionable(schema.value!.fields as any);
  console.log(fieldsMap.value);
  relationsMap.value = mapFromCollectionable(schema.value!.relations as any);
  loading.value = false;
  tableLoading.value = false;
};

onMounted(() => {
  init();
});

async function uploadItem(collectionName: string, data: JSON) {
  const file = createJsonFile(data, collectionName);
  await uploadFile(collectionName, file, clientB.value);
}

const handleExportClick = ref(async () => {
  const failedCollections = new Map();
  const textLog: string[] = [];
  const dataMap = new Map();

  for (let element of selected.value) {
    let originData = await exportData(element.collection);
    originData = removeFields(originData, ["user_created", "user_updated"]);
    const fieldsToRemove = getRelationFields(
      fieldsMap.value,
      element.collection
    );
    console.log("fieldsToRemove", fieldsToRemove);

    const secureData = removeFields(originData, fieldsToRemove);

    dataMap.set(element.collection, {
      origin: originData,
      secure: secureData,
    });
  }

  for (let [collectionName, data] of dataMap.entries()) {
    try {
      console.log("data.secure", data.secure);
      await uploadItem(collectionName, data.secure);
    } catch (e) {
      failedCollections.set(collectionName, false);
      textLog.push(
        createLogMessage(
          logMessageTypes.error,
          `${collectionName} secure load faild ${e?.errors?.[0]?.message}`
        )
      );
    }
  }
  for (let [collectionName, data] of dataMap.entries()) {
    try {
      if (failedCollections.has(collectionName)) continue;
      await uploadItem(collectionName, data.origin);
      textLog.push(
        createLogMessage(
          logMessageTypes.success,
          `${collectionName} successfully loaded`
        )
      );
    } catch (e) {
      textLog.push(
        createLogMessage(
          logMessageTypes.error,
          `${collectionName} origin data load faild ${e?.errors?.[0]?.message}`
        )
      );
    }
  }

  if (failedCollections.size === 0) {
    notificationsStore.add({
      type: "success",
      title: "Item successfully transferred",
      dialog: true,
    });
  }

  log.value = textLog.join("");
});

function updateModelValue(newValue) {
  const selectableTable =
    step.value === 0 ? collectionsSorted.value : collectionForConfirm.value;
  if (newValue.length === 0 || newValue.length === selectableTable.length) {
    selected.value = newValue;
  }
}

function handleItemSelect(event) {
  if (event.value) {
    tableLoading.value = true;
    let relatedCollections: Collection[] = [];
    if (step.value === 0) {
      relatedCollections = getRelatedCollection(event.item.collection);
      if (isCollectionSearchDeep.value) {
        const tmpRelatedCollection =
          getDeepRelatedCollection(relatedCollections);
        for (let [collectionName, value] of tmpRelatedCollection) {
          relatedCollections.push(collectionsMap.value[collectionName]);
        }
        console.log("getDeepRelatedCollection", tmpRelatedCollection);
      }
    }
    selected.value = uniqBy(
      [...selected.value, ...relatedCollections, event.item],
      "collection"
    );
    tableLoading.value = false;
  } else {
    const tmp = [...selected.value].filter((value) => {
      return value.collection !== event.item.collection;
    });

    selected.value = tmp;
  }
}
function handleItemUpdate(newValue) {
  console.log("handleItemUpdate", newValue);
}

function getDeepRelatedCollection(
  collections: Collection[],
  i = 0,
  map: Map<string, number> = new Map()
) {
  for (let element of collections) {
    const relatedCollections = getRelatedCollection(element.collection);
    console.log(i, element.collection, relatedCollections);
    relatedCollections.forEach((item) => {
      if (!map.has(item.collection)) {
        map.set(item.collection, i);
        const newRelatedCollection = getRelatedCollection(item.collection);
        getDeepRelatedCollection(newRelatedCollection, i + 1, map);
      }
    });
  }

  return map;
}

function getRelatedCollection(collectionName: string) {
  if (relationsMap.value.has(collectionName)) {
    const collections: Collection[] = [];

    relationsMap.value.get(collectionName).forEach((collection) => {
      if (collectionsMap.value[collection.related_collection]) {
        collections.push(collectionsMap.value[collection.related_collection]);
      }
    });

    return collections;
  }

  return [];
}

function handleTransferClick() {
  step.value = 1;
  console.log("selected.value", selected.value);
}
function handleReturnToSelectionClick() {
  step.value = 0;
}
</script>

<template>
  <private-view title="Collection transfer">
    <template v-slot:navigation>
      <Nav :activeItem="nav['universal-collection-transfer'].id" />
    </template>
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
          <v-button @click="handleReturnToSelectionClick" v-if="step === 1"
            >Return to selection</v-button
          >
          <v-button @click="handleTransferClick" v-if="step === 0"
            >Transfer collections</v-button
          >
          <v-button
            @click="handleExportClick"
            v-if="step === 1"
            :disabled="!selected.length"
            >Confirm Transfer</v-button
          >
        </div>
        <div class="mb controls">
          <v-checkbox
            v-if="step === 0"
            v-model="withSystemCollection"
            label="Show system collection"
          />
          <v-checkbox
            v-if="step === 0"
            v-model="isCollectionSearchDeep"
            label="Deep relation search"
          />
        </div>
        <v-textarea
          v-model="log"
          :disabled="true"
          v-if="step === 1 && log.length"
        />
        <h1 v-if="step === 1">Confirm collections</h1>
        <VTable
          :showSelect="'multiple'"
          :headers="COLLECTION_HEADER"
          :items="step === 0 ? collectionsSorted : collectionForConfirm"
          :itemKey="'collection'"
          :modelValue="selected"
          :loading="tableLoading"
          @item-selected="handleItemSelect"
          @update:items="handleItemUpdate"
          @update:modelValue="updateModelValue"
        ></VTable>
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
</style>
