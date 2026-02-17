import { ref } from "vue";
import { useApi } from "@directus/extensions-sdk";
import { getEndpoint } from "@directus/utils";
import { getPublicURL } from "../utils";
import { utilsImport } from "@directus/sdk";

export const useExportImport = () => {
  const api = useApi();
  const uploading = ref(false);
  const importing = ref(false);

  const exportData = async (collectionName: string) => {
    const endpoint = getEndpoint(collectionName);

    // usually getEndpoint contains leading slash, but here we need to remove it
    const url = getPublicURL() + endpoint.substring(1);

    const params: Record<string, unknown> = {
      access_token: (
        api.defaults.headers.common["Authorization"] as string
      ).substring(7),
      limit: -1,
      export: "json",
    };

    const exportUrl = api.getUri({
      url,
      params,
    });

    return fetch(exportUrl).then((data) => data.json());
  };

  const uploadFile = async (collectionName: string, file: File, remoteClient: any) => {
    uploading.value = true;
    importing.value = false;

    const formData = new FormData();
    formData.append('file', file);

    try {
      await remoteClient.request(utilsImport(collectionName, formData));
    } finally {
      uploading.value = false;
      importing.value = false;
    }
  };

  return { uploading, importing, uploadFile, exportData };
};