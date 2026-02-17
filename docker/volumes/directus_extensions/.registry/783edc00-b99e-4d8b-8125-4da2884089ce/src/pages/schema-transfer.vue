<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useStores } from "@directus/extensions-sdk";
import { uniqBy } from "lodash";

import { COLLECTION_HEADER, COLLECTION_TO_EXCLUDE_FROM_SCHEMA } from "../const";
import Nav from "../components/nav.vue";
import DirectusClientSwitch from "../components/DirectusClientSwitch.vue";
import { nav } from "../path";
import { mapFromCollectionable, recordFromCollectionable } from "../utils";
import { useSchema, useDirectusClients } from "../hooks";

import type { Collection, Collectionable } from "../types";

const { useNotificationsStore } = useStores();
const notificationsStore = useNotificationsStore();

const loading = ref(true);
const schema = ref<Awaited<ReturnType<typeof useSchema>> | null>(null);
const relationsMap = ref<any>(null);
const changedCollections = ref<any>(null);
const selected = ref<Collection[]>([]);
const clientsData = ref<DirectusClients | null>(null);

const init = async () => {
  clientsData.value = await useDirectusClients();
  requestInitData();
};
async function requestInitData() {
  loading.value = true;

  // getting schema and sort it to collections, fields and relations
  schema.value = await useSchema(
    clientsData.value.clientA,
    clientsData.value.clientB
  );
  relationsMap.value = schema.value.relationsMap;
  console.log("relationsMap:", relationsMap.value);
  const accChengedCollection = uniqBy(
    [
      ...(schema.value?.diffResponce?.diff?.collections || []),
      ...(schema.value?.diffResponce?.diff?.fields || []),
      ...(schema.value?.diffResponce?.diff?.relations || []),
    ],
    "collection"
  );

  changedCollections.value = accChengedCollection.filter(
    (item) => !COLLECTION_TO_EXCLUDE_FROM_SCHEMA[item.collection as string]
  );

  loading.value = false;
}
onMounted(() => {
  init();
});

const handleApplyClick = ref(async () => {
  if (!schema.value)
    throw new Error("schema transfer applied before data initialization");
  try {
    console.log("relationsMap.value", relationsMap.value);

    const diffCollections = recordFromCollectionable(selected.value);
    const diffFields = mapFromCollectionable(
      schema.value?.diffResponce.diff.fields
    );
    const diffRelations = mapFromCollectionable(
      schema.value?.diffResponce.diff.relations
    );
    console.log(
      "diffCollections, diffFields, diffRelations",
      diffCollections,
      diffFields,
      diffRelations
    );
    console.log("relationsMap.value", relationsMap.value);
    const tmpSchemaDiff = {
      hash: schema.value?.diffResponce.hash,
      diff: {
        collections: [] as any[],
        fields: [] as any[],
        relations: [] as any[],
      },
    };
    for (let [key, item] of Object.entries(diffCollections)) {
      if (COLLECTION_TO_EXCLUDE_FROM_SCHEMA[key]) continue;

      tmpSchemaDiff.diff.collections.push(item);
      if (diffFields.has(key)) {
        tmpSchemaDiff.diff.fields.push(
          ...(diffFields.get(key) as Collectionable[])
        );
      }
      if (diffRelations.has(key)) {
        tmpSchemaDiff.diff.relations.push(
          ...(diffRelations.get(key) as Collectionable[])
        );
      }
    }

    console.log("tmpSchemaDiff", tmpSchemaDiff);

    await schema.value.applySchema(tmpSchemaDiff);

    notificationsStore.add({
      type: "success",
      title: "Change applied successfully",
      dialog: false,
    });
    requestInitData();
  } catch (e) {
    console.error(e);
    notificationsStore.add({
      type: "error",
      title: "Error duiring apply",
      text: e?.errors?.[0]?.message,
      dialog: true,
    });
  }
});

async function handleApplyWholeClick() {
  try {
    await schema.value?.applySchema();
    notificationsStore.add({
      type: "success",
      title: "Change applied successfully",
      dialog: false,
    });
  } catch (e) {
    console.error(e);
    notificationsStore.add({
      type: "error",
      title: "Error duiring apply",
      text: e?.errors?.[0]?.message,
      dialog: true,
    });
  }
}

function handleClientChange(clientId: string) {
  requestInitData();
}
</script>

<template>
  <private-view title="Export">
    <template v-slot:navigation>
      <Nav :activeItem="nav.main.id" />
    </template>
    <DirectusClientSwitch
      v-if="clientsData"
      v-bind="clientsData"
      @client-change="handleClientChange"
    />
    <div class="wrapper">
      <div class="controls mb32">
        <v-button
          @click="handleApplyClick"
          :disabled="!schema?.canApply || selected.length === 0"
        >
          Apply selected diff
        </v-button>

        <v-button
          @click="handleApplyWholeClick"
          :disabled="!schema?.canApply"
          :loading="clientsData.loading"
        >
          Apply whole diff
        </v-button>
      </div>
      <div class="loaderWrapper" v-if="loading || clientsData.loading">
        <v-progress-circular class="loader" indeterminate />
      </div>
      <div v-else>
        <VTable
          class="mb32"
          :showSelect="'multiple'"
          :headers="COLLECTION_HEADER"
          :items="changedCollections"
          :itemKey="'collection'"
          :loading="loading || clientsData.loading"
          v-model="selected"
        ></VTable>
        <interface-input-code
          :value="schema?.diffResponce"
          :line-number="false"
          :alt-options="{ gutters: false }"
          language="json"
        />
        <h1 v-if="!schema?.canApply">Everything is updated</h1>
      </div>
    </div>
  </private-view>
</template>

<style lang="scss" scoped>
.loaderWrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 200px;
}
.wrapper {
  margin: 24px 32px;
}

.mb32 {
  margin-bottom: 32px;
}

.controls {
  display: flex;
  gap: 32px;
}
</style>
