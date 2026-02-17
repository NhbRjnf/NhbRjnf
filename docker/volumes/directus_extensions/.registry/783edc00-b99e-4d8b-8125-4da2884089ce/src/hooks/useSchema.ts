import { cloneDeep } from "lodash";
import { SchemaDiffOutput, SchemaSnapshotOutput, schemaApply, schemaDiff, schemaSnapshot } from "@directus/sdk";

import { mapFromCollectionable, recordFromCollectionable } from "../utils";
import { COLLECTION_TO_EXCLUDE_FROM_SCHEMA } from "../const";

import type { Collection, Collectionable, DirectusClient } from "../types";

export const useSchema = async (clientA: DirectusClient, clientB: DirectusClient) => {
  // getting current Directus snapshot
  let snapshot = await clientA.request(schemaSnapshot());
  const collectionsRecord = recordFromCollectionable<Collection>(
    snapshot!.collections as any
  );
  const fieldsMap = mapFromCollectionable(snapshot!.fields as any);
  const relationsMap = mapFromCollectionable(snapshot!.relations as any);

  // getting difference between current and remote directus
  let diffResponce = await clientB.request(
    schemaDiff(snapshot as SchemaSnapshotOutput)
  );

  let canApply: boolean | null = null;
  // if diff successfully loaded, we can apply schema on remote directus client
  //@ts-ignore
  if (diffResponce?.status === 204) {
    canApply = false;
  } else {
    canApply = true;
  }

  function applySchema(diff: SchemaDiffOutput = diffResponce) {
    const tmpDiff = cloneDeep(diff);
    // before apply diff, we need exclude necessary collection
    for (let [key, element] of Object.entries(tmpDiff.diff)) {
      tmpDiff.diff[key] = (element as Collectionable[]).filter(
        (item) => !COLLECTION_TO_EXCLUDE_FROM_SCHEMA[item.collection]
      );
    }

    console.log('"applySchema" diff for apply after check: ', tmpDiff);

    return clientB.request(
      schemaApply(tmpDiff)
    );
  }

  return {
    snapshot,
    diffResponce,
    canApply,
    collectionsRecord,
    fieldsMap,
    relationsMap,
    applySchema,
  }
};