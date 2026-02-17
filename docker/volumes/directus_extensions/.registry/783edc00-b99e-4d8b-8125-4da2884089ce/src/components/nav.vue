<script setup lang="ts">
import { nav } from "../path";

const props = defineProps(["activeItem"]);
</script>

<template>
  <v-list>
    <v-list-item
      v-for="item in nav"
      :active="item.id === props.activeItem"
      :nav="true"
      :to="item.url"
      >{{ item.name }}</v-list-item
    >
  </v-list>
</template>

<style lang="scss" scoped></style>
