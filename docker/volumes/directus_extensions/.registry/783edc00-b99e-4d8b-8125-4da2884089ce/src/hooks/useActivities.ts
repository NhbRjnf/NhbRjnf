import { readActivities } from "@directus/sdk";
import type { ActivitiesMap, Activity, Collectionable, DirectusClient } from "../types";
import { ACTIVITIES_PER_REQUEST } from "../const";

export type FilterFn = (i?: number) => any

function processActivities(newActivities: Activity[], activities = new Map()) {
  for (let element of newActivities) {
    const tmpMap = new Map(activities.get(element.collection) || []);
    tmpMap.set(element.item, {
      ...(tmpMap.get(element.item) || {}),
      [element.timestamp]: element,
    });
    activities.set(element.collection, tmpMap);
  }

  return activities as ActivitiesMap;
}

export const useActivities = (client: DirectusClient) => {
  const getActivities = async (filterFn: FilterFn, activities: ActivitiesMap = new Map()) => {
    let page = 1;
    let activitiesResponse = await client.request(readActivities(filterFn(page))) as Collectionable[];
    activities = processActivities(activitiesResponse as Activity[], activities);

    while (activitiesResponse.length === ACTIVITIES_PER_REQUEST) {
      page++;
      activitiesResponse = await client.request(
        readActivities(filterFn(page))
      ) as Collectionable[];
      activities = processActivities(activitiesResponse as Activity[], activities);
    }

    return activities;
  }

  return {
    getActivities
  }
};
