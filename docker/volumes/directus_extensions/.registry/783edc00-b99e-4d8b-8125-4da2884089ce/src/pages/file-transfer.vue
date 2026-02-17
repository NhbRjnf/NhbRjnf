<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted, watch } from "vue";
import { useApi, useStores } from "@directus/extensions-sdk";
import { debounce } from "lodash";
import {
  createFolder,
  readFiles,
  readFolders,
  updateFile,
  updateFolder,
} from "@directus/sdk";
import { format } from "date-fns";

import FileStructure from "../components/FileStructure.vue";
import { useDirectusClients } from "../hooks/useDirectusClients";
import Nav from "../components/nav.vue";
import DirectusClientSwitch from "../components/DirectusClientSwitch.vue";
import { nav } from "../path";
import { blobToBase64, getPublicURL, recordFromArrWithIds } from "../utils";
import { Folder, FolderRaw } from "../types";

const FILES_PER_PAGE = 20;
const TABLE_HEADER = [
  { text: "ID", value: "id" },
  { text: "", value: "img", width: "300" },
  { text: "Title", value: "title", width: "450" },
  { text: "Modified on", value: "modified_on", width: "250" },
  { text: "Modified by", value: "modified_by", width: "250" },
];

type AssetFile = ReturnType<typeof readFiles> & {
  id: string;
  folder: string;
  uploaded_on: string;
  modified_on: string;
  fileBlob?: Blob;
  fileBase64?: string;
  status?: "new" | "changed";
};

type FilesRecord = { [key: string]: AssetFile };

const { useNotificationsStore } = useStores();
const notificationsStore = useNotificationsStore();

const api = useApi();
const loading = ref(true);
const selected = ref([] as AssetFile[]);
const clientsData = ref<DirectusClients | null>(null);

const search = ref<string | null>(null);
const filterNewOrChanged = ref(false);
const filesA = ref<FilesRecord>({});
const filesB = ref<FilesRecord>({});
const filesCount = ref(0);
const currentPage = ref(1);
const foldersA = ref<Folder[]>([]);
const foldersRawA = ref<FolderRaw[]>([]);
const foldersB = ref<Folder[]>([]);
const foldersRawB = ref<FolderRaw[]>([]);
const activeFolder = ref<null | string>(null);
const sortedFiles = computed(() => {
  if (Object.values(filesA.value).length === 0) return [];
  let result = Object.values(filesA.value);

  if (filterNewOrChanged.value) {
    result = result.filter(
      (item) => item.status === "new" || item.status === "changed"
    );
  }

  if (!activeFolder.value) {
    return result;
  }

  result = Object.values(result).filter(
    (item) => item.folder === activeFolder.value
  );

  return result;
});
const paginationState = computed(() => {
  return {
    length: Math.ceil(sortedFiles.value.length / FILES_PER_PAGE),
    totalVisible: 10,
  };
});

async function loadFile(file): Promise<AssetFile> {
  const fileBlob = await getAsset(file.id);
  const fileBase64 = fileBlob ? await blobToBase64(fileBlob) : undefined;

  return {
    ...file,
    fileBlob,
    fileBase64,
  };
}

async function getFiles(client, searchStr: string = ""): Promise<FilesRecord> {
  const files = await client.request(
    readFiles({
      limit: -1,
      search: searchStr,
      fields: [
        "*",
        "id",
        "title",
        "uploaded_on",
        "modified_on",
        { modified_by: ["id", "email", "first_name", "last_name", "avatar"] },
        { uploaded_by: ["id", "email", "first_name", "last_name", "avatar"] },
      ],
    })
  );

  return recordFromArrWithIds<AssetFile>(files);
}

async function getFolders(client) {
  const foldersRaw = await client.request(readFolders());
  return foldersRaw;
}

function nestFolders(rawFolders: FolderRaw[]): Folder[] {
  return rawFolders
    .map((rawFolder) => nestChildren(rawFolder, rawFolders))
    .filter((folder) => folder.parent === null);
}

function nestChildren(rawFolder: FolderRaw, rawFolders: FolderRaw[]): Folder {
  const folder: Folder = { ...rawFolder };

  const children = rawFolders.reduce<FolderRaw[]>((acc, childFolder) => {
    if (
      childFolder.parent === rawFolder.id &&
      childFolder.id !== rawFolder.id
    ) {
      acc.push(nestChildren(childFolder, rawFolders));
    }

    return acc;
  }, []);

  if (children.length > 0) {
    folder.children = children;
  }

  return folder;
}

const init = async () => {
  clientsData.value = await useDirectusClients();
  requestInitData();
};

async function fetchFoldersA() {
  foldersRawA.value = await getFolders(clientsData.value.clientA);
  foldersA.value = nestFolders(foldersRawA.value);
}

async function fetchFoldersB() {
  foldersRawB.value = await getFolders(clientsData.value.clientB);
  foldersB.value = nestFolders(foldersRawB.value);
}
async function requestInitData() {
  filesCount.value = (
    await clientsData.value.clientA.request(
      readFiles({
        //@ts-ignore
        aggregate: { countDistinct: "id" },
      })
    )
  )[0].countDistinct.id;

  const filesAResp = await getFiles(clientsData.value.clientA);
  const filesBResp = await getFiles(clientsData.value.clientB);
  for (let item of Object.values(filesAResp)) {
    const rightItem = filesBResp[item.id];
    if (rightItem) {
      if (new Date(item.modified_on) > new Date(rightItem.modified_on)) {
        filesAResp[item.id] = {
          ...item,
          status: "changed",
        } as AssetFile;
      }
    } else {
      filesAResp[item.id] = {
        ...item,
        status: "new",
      } as AssetFile;
    }
  }
  filesA.value = filesAResp;
  filesB.value = filesBResp;

  await fetchFoldersA();
  await fetchFoldersB();
  loading.value = false;
}
onMounted(() => {
  init();
});

async function getAsset(id: string) {
  const url = getPublicURL() + `assets/${id}?download=true`;

  const params: Record<string, unknown> = {
    access_token: (
      api.defaults.headers.common["Authorization"] as string
    ).substring(7),
  };

  const exportUrl = api.getUri({
    url,
    params,
  });

  return fetch(exportUrl)
    .then(async (data) => {
      if (data.status !== 200) {
        const response = await data.json();
        throw new Error(response?.errors?.[0].message);
      }
      return data.blob();
    })
    .catch((e) => console.log("Failed to load asset: ", e));
}

const handleSearchChange = debounce(async () => {
  loading.value = true;
  filesA.value = await getFiles(search.value || "");
  loading.value = false;
}, 300);

async function createMissedFolders(files: AssetFile[]) {
  const tmpFolders = [];
  for (let item of files) {
    const folderId = item.folder;
    if (item.folder && !foldersB.value[folderId]) {
      const chain = findFoldersChain(item.folder, foldersA.value);
      await updateFolders(chain, foldersB.value);
      await fetchFoldersB();
    }
  }
}

function findFoldersChain(
  id: string,
  folders: Folder[],
  result: string[] = []
) {
  for (let item of folders) {
    if (item.id === id)
      return [...result, { id: item.id, name: item.name, parent: item.parent }];
    if (item.children) {
      const prevResult = findFoldersChain(id, item.children, [
        ...result,
        { id: item.id, name: item.name, parent: item.parent },
      ]);
      if (prevResult === null) continue;
      else return prevResult;
    }
  }

  return null;
}

async function handleTransferFile() {
  loading.value = true;
  let error = false;
  await createMissedFolders(selected.value);
  for (let item of selected.value) {
    const file = new File([item.fileBlob], item.filename_download, {
      type: item.fileBlob.type,
    });

    const formData = new FormData();
    // in case to save initial id we need to add fields to form
    for (let [attr, value] of Object.entries(item)) {
      if (
        attr === "fileBlob" ||
        attr === "fileBase64" ||
        attr === "metadata" ||
        attr === "modified_by" ||
        attr === "uploaded_by" ||
        attr === "filesize"
      )
        continue;
      formData.append(attr, value);
    }
    formData.append("file", file);
    for (const item of formData.entries()) {
      console.log(item);
    }

    try {
      await clientsData.value.clientB.request(updateFile(item.id, formData));
    } catch (e) {
      error = true;
      notificationsStore.add({
        type: "error",
        title: "Error duiring upload: " + item.name,
        text: e?.errors?.[0]?.message,
        dialog: true,
      });
    }
  }
  if (!error) {
    notificationsStore.add({
      type: "success",
      title: "Items successfully transferred",
    });
  }
  await requestInitData();
  loading.value = false;
}

function handleClick(id: string | null) {
  activeFolder.value = id;
  currentPage.value = 1;
  selected.value = [];
}

function getFilesToShow() {
  if (sortedFiles.value.length === 0) return [];
  const startPosition =
    currentPage.value === 1 ? 0 : (currentPage.value - 1) * FILES_PER_PAGE;

  let tmpFiles = Object.values(sortedFiles.value).slice(
    startPosition,
    startPosition + FILES_PER_PAGE
  );
  for (let item of tmpFiles) {
    if (item.fileBase64) continue;
    filesA.value[item.id] = {
      ...item,
      fileBase64: "loading",
    } as AssetFile;
    loadFile(item).then((loadedFile) => {
      filesA.value[loadedFile.id] = loadedFile;
    });
  }

  return tmpFiles;
}

async function updateFolders(foldersA: Folder[], foldersB: Folder[]) {
  const foldersBRecord = recordFromArrWithIds(foldersB);
  for (let item of foldersA) {
    const rightItem = foldersBRecord[item.id];
    if (rightItem) {
      // check if folder parent changed
      if (item.parent !== rightItem.parent || item.name !== rightItem.name) {
        await clientsData.value.clientB.request(updateFolder(item.id, item));
      }
    } else {
      await clientsData.value.clientB.request(createFolder(item));
    }

    if (item.children) {
      updateFolders(item.children, rightItem?.children || []);
    }
  }
}

async function handleSyncFoldersStructure() {
  loading.value = true;
  await updateFolders(foldersA.value, foldersB.value);
  await fetchFoldersB();
  loading.value = false;
}
function handleClientChange(clientId: string) {
  requestInitData();
}
</script>

<template>
  <private-view title="Files">
    <template v-slot:navigation>
      <Nav :activeItem="nav['file-transfer'].id" />
    </template>
    <DirectusClientSwitch
      v-if="clientsData"
      v-bind="clientsData"
      @client-change="handleClientChange"
    />
    <div class="wrapper">
      <div>
        <v-button
          class="syncFoldersButton"
          @click="handleSyncFoldersStructure"
          :loading="loading || clientsData.loading"
        >
          Sync folders structure
        </v-button>
        <FileStructure
          :folders="foldersA"
          :onClick="handleClick"
          :active-folder="activeFolder"
        />
      </div>
      <div>
        <div class="mb32 controls">
          <v-input
            v-model="search"
            @update:modelValue="handleSearchChange"
            class="searchInput"
            type="search"
            :placeholder="'Search files'"
            :full-width="false"
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
            @click="handleTransferFile"
            :loading="loading || clientsData.loading"
            :disabled="!selected.length"
          >
            Transfer files
          </v-button>
        </div>
        <div class="mb32 controls">
          <v-checkbox
            v-model="filterNewOrChanged"
            label="Show only new or changed files"
          />
        </div>
        <VTable
          class="mb32"
          :showSelect="'multiple'"
          :headers="TABLE_HEADER"
          :items="getFilesToShow()"
          :itemKey="'id'"
          v-model="selected"
          :loading="loading || clientsData.loading"
        >
          <template #[`item.id`]="{ item }">
            <p
              :class="{
                idCell: true,
                new: item.status === 'new',
                changed: item.status === 'changed',
              }"
            >
              {{ item.id }}
            </p>
          </template>
          <template #[`item.img`]="{ item }">
            <img
              class="impPreview"
              v-if="item.fileBase64"
              :src="item.fileBase64"
              width="300"
              height="200"
            />
          </template>
          <template #[`item.modified_on`]="{ item }">
            <p
              :class="{
                new: item.status === 'new',
                changed: item.status === 'changed',
              }"
            >
              {{
                format(
                  item?.modified_on ?? item.uploaded_on,
                  "dd.MM.yyyy HH:mm:ss"
                )
              }}
            </p>
          </template>
          <template #[`item.modified_by`]="{ item }">
            <a
              v-if="item?.modified_by?.id"
              class="link"
              target="_blank"
              :href="`users/${item?.modified_by?.id}`"
            >
              {{
                `${item.modified_by.first_name} ${item.modified_by.last_name}`
              }}
            </a>
            <a
              v-if="item?.uploaded_by?.id"
              class="link"
              target="_blank"
              :href="`users/${item.uploaded_by.id}`"
            >
              {{
                `${item.uploaded_by.first_name} ${item.uploaded_by.last_name}`
              }}
            </a>
          </template>
        </VTable>
        <v-pagination v-model="currentPage" v-bind="paginationState" />
      </div>
    </div>
  </private-view>
</template>

<style lang="scss" scoped>
.idCell {
  text-wrap: wrap;
}
.new {
  color: var(--success);
}
.changed {
  color: var(--warning);
}
.loaderWrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 200px;
}
.wrapper {
  margin: 24px 32px;
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 20px;
}

.mb32 {
  margin-bottom: 32px;
}

.controls {
  display: flex;
  gap: 32px;
}

.searchInput {
  width: 100%;
}

.impPreview {
  object-fit: contain;
}

.syncFoldersButton {
  width: 100%;
  margin-bottom: 20px;
}
</style>
