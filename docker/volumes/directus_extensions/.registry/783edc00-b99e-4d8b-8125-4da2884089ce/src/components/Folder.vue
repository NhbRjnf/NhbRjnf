<script lang="ts">
export default {
  name: "Folder",
};
</script>
<script setup lang="ts">
import type { Folder as FolderType } from "../types";

const { folder, onClick } = defineProps<{
  folder: FolderType;
  onClick: (id: string) => void;
  activeFolder: string | null;
}>();

function handleClick() {
  onClick(folder.id);
}
</script>

<template>
  <div class="folder-wrapper">
    <button
      class="button"
      :class="{ active: activeFolder === folder.id }"
      @click="handleClick"
    >
      {{ folder.name }}
    </button>
    <div v-if="folder.children">
      <Folder
        v-for="item of folder.children"
        :key="item.id"
        :folder="item"
        :onClick="onClick"
        :activeFolder="activeFolder"
      />
    </div>
  </div>
</template>

<style lang="scss" scoped>
.folder-wrapper {
  margin-left: 20px;
}

.button {
  padding: 5px;
  border-radius: 5px;
}
.active {
  background-color: var(--theme--background-accent);
}
</style>
