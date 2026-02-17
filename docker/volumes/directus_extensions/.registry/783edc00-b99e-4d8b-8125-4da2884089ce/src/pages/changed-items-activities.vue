<script setup lang="ts">
import { ref, onMounted } from "vue";
import { format } from "date-fns";

import { useDirectusClients, useActivities } from "../hooks";
import DirectusClientSwitch from "../components/DirectusClientSwitch.vue";
import Nav from "../components/nav.vue";

import { ACTIVITIES_PER_REQUEST, ACTIVITIES_TABLE_HEADER } from "../const";

import type { ActivitiesMap, Collectionable, Activity } from "../types";

const loading = ref(true);
const searchParams = ref(new URLSearchParams(window.location.search));
const clientsData = ref<DirectusClients | null>(null);
const rawActivities = ref<ActivitiesMap>(new Map());
const activities = ref<Collectionable[]>([]);
const collectionName = ref(searchParams.value.get("collection") || "");
const date = ref(searchParams.value.get("date") || "");

onMounted(async () => {
  clientsData.value = await useDirectusClients();
  const getFilter = (page: number = 1) => {
    const filter = {
      limit: ACTIVITIES_PER_REQUEST,
      page,
      fields: [
        "collection",
        "action",
        "timestamp",
        "item",
        { user: ["id", "email", "first_name", "last_name", "avatar"] },
      ],
      filter: {
        _and: [
          {
            action: {
              _in: ["create", "update", "delete"],
            },
          },
          {
            collection: {
              _eq: collectionName.value,
            },
          },
        ],
      } as any,
    };

    if (date.value) {
      filter.filter["_and"].push({
        timestamp: {
          _gte: date.value,
        },
      });
    }

    console.log(filter);

    return filter;
  };
  const { getActivities } = useActivities(clientsData.value.clientA);
  console.log(date.value);
  rawActivities.value = await getActivities(getFilter, rawActivities.value);
  if (rawActivities.value.has(collectionName.value)) {
    const collectionActivities = rawActivities.value.get(collectionName.value)!;
    for (let element of collectionActivities.values()) {
      console.log(element);
      const mainElement = selectMainActivity(Object.values(element));
      activities.value = [...activities.value, mainElement];
    }
  }
  loading.value = false;
});

function selectMainActivity(activities: Activity[]): Activity {
  if (activities[activities.length - 1].action === "delete")
    return activities[activities.length - 1];
  if (activities[0].action === "create") return activities[0];
  return activities[activities.length - 1];
}
</script>

<template>
  <private-view title="Activities">
    <template v-slot:navigation>
      <Nav :activeItem="null" />
    </template>
    <div class="wrapper">
      <div class="controls mb32"></div>
      <div class="loaderWrapper" v-if="loading">
        <v-progress-circular class="loader" indeterminate />
      </div>
      <div v-else>
        <h1 class="mb32">Collection: {{ collectionName }}</h1>
        <VTable
          :headers="ACTIVITIES_TABLE_HEADER"
          :items="activities"
          :itemKey="'collection'"
          :loading="loading"
        >
          <template #[`item.action`]="{ item }">
            <div
              :class="[
                'activity',
                {
                  delete: item.action === 'delete',
                  create: item.action === 'create',
                  update: item.action === 'update',
                },
              ]"
            >
              {{ item.action && item.action }}
            </div>
          </template>
          <template #[`item.item`]="{ item }">
            <a
              class="link"
              target="_blank"
              :href="`content/${collectionName}/${item.item}`"
            >
              {{ item.item }}
            </a>
          </template>
          <template #[`item.timestamp`]="{ item }">
            {{
              item.timestamp && format(item.timestamp, "dd.MM.yyyy HH:mm:ss")
            }}
          </template>
          <template #[`item.user`]="{ item }">
            <a class="link" target="_blank" :href="`users/${item.user.id}`">
              {{ `${item.user.first_name} ${item.user.last_name}` }}
            </a>
          </template>
        </VTable>
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

.activity {
  margin: auto;
  padding: 4px;
  border-radius: 4px;
}
.delete {
  color: var(--theme--danger);
  background-color: var(--danger-25);
}
.create {
  color: var(--theme--primary);
  background-color: var(--theme--primary-subdued);
}
.update {
  color: var(--blue);
  background-color: var(--blue-25);
}
.link {
  text-decoration: underline;
  cursor: pointer;
}
</style>
