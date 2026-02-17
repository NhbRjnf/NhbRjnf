<script setup lang="ts">
import { useDirectusClients, DirectusClients } from "../hooks";
import { ref, computed, watch } from "vue";

const {
  clientA,
  clientB,
  masterClient,
  remoteClient,
  clientRecords,
  createAuthorizedClient,
  saveClientSelect,
  changeRemoteClient,
} = defineProps<DirectusClients>();

const emit = defineEmits<{
  (e: "clientChange", value: string | null): void;
}>();

const select = ref(remoteClient?.id);
const items = computed(() => {
  return (Object.values(clientRecords) || []).map((item) => ({
    value: item.id,
    text: `#${item.id} /${item.type}/ ${item.name}`,
  }));
});

async function handleSelectChange(clientId) {
  await changeRemoteClient(clientId);
  emit("clientChange", clientId);
}
</script>

<template>
  <div class="client-switch-wrapper">
    <div>
      <h1>Current Client:</h1>
      <p>
        #{{ masterClient?.id }} /{{ masterClient?.type }}/
        {{ masterClient?.name }}
      </p>
      <a href="masterClient.url">{{ masterClient?.url }}</a>
    </div>
    <div class="client-select">
      <h1>Remote Client:</h1>
      <v-select
        v-model="select"
        :items="items"
        @update:model-value="handleSelectChange"
      />
    </div>
  </div>
</template>

<style lang="scss" scoped>
.client-switch-wrapper {
  margin: 24px 32px;
  display: flex;
  gap: 32px;
}

.client-select {
  min-width: 500px;
}

.button {
  padding: 5px;
  border-radius: 5px;
}

.active {
  background-color: var(--theme--background-accent);
}
</style>
