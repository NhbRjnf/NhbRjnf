import { onMounted } from "vue";
import { initDirectusClients } from "./hooks/useDirectusClients";
import { ACTIVITIES_PER_REQUEST } from "./const";
import { readActivities } from "@directus/sdk";
import { clientA, searchParams } from "./changed-items-activities.vue";

onMounted(async () => {
  const [client1, client2] = await initDirectusClients();
  clientA.value = client1;
  const collectionName = searchParams.value.get('collection');
  let page = 1;

  let activitiesResponse = await clientA.value.request(
    readActivities({
      limit: ACTIVITIES_PER_REQUEST,
      page,
      filter: {
        _and: [
          {
            action: {
              _in: ["create", "update", "delete"],
            },
          },
          {
            collection: {
              _eq: 
                    }
          },
        ],
      } as any,
    })
  );
});
