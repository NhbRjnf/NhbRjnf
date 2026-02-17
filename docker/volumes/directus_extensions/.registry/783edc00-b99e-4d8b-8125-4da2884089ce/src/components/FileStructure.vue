<script setup lang="ts">
import Folder from "./Folder.vue";
import type { FolderRaw } from "../types";

const { folders, onClick, activeFolder } = defineProps<{
  folders: FolderRaw[];
  onClick: (id: string | null) => void;
  activeFolder: string | null;
}>();
</script>

<template>
  <div class="file-wrapper">
    <button
      class="button"
      :class="{ active: activeFolder === null }"
      @click="() => onClick(null)"
    >
      All files
    </button>
    <Folder
      v-for="item of folders"
      :folder="item"
      :onClick="onClick"
      :activeFolder="activeFolder"
    />
  </div>
</template>

<style lang="scss" scoped>
.file-wrapper {
}

.button {
  padding: 5px;
  border-radius: 5px;
}

.active {
  background-color: var(--theme--background-accent);
}
</style>
