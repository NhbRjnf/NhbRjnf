import {
  AuthenticationClient,
  DirectusClient as DClient,
  RestClient,
  readActivities,
} from "@directus/sdk";

import type {
  Collection as DirectusCollection
} from "@directus/shared/types";

export type DirectusClient = DClient<any> & AuthenticationClient<any> & RestClient<any>

export type ExportElement = {
  collection: string;
  exclude_fields?: string[];
  related_collections?: ExportElement[];
  referenced_collections?: ExportElement[];
};
export type ExportSchemaConfig = {
  export: boolean;
} & ExportElement;

export type Collectionable = { collection: string };

export type Collection = DirectusCollection & {
  meta: {
    system?: boolean;
    export_schema?: ExportSchemaConfig;
    imported_at?: string;
  };
  last_sync_date?: string;
  exportOrder: ExportElement[];
  changed: boolean;
};

export type CollectionsRecord = Record<string, Collection>;

export type Activity = ReturnType<typeof readActivities> & {
  collection: string;
  item: string;
  timestamp: string;
  action: string;
}

export type ActivitiesMap = Map<string, Map<string, Record<string, Activity>>>;

export type ExportDateCollection = {
  id: string;
  data_update_date: Date;
  last_sync_date: Date;
};

export type FolderRaw = {
  id: string;
  name: string;
  parent: string | null;
};

export type Folder = {
  id: string;
  name: string;
  parent: string | null;
  children?: Folder[];
};