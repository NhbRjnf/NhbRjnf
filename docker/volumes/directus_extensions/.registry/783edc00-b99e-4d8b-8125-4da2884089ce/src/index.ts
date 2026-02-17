import { defineModule } from '@directus/extensions-sdk';
import SchemaTransferComponent from './pages/schema-transfer.vue';
import ItemTransferComponent from './pages/collections-transfer.vue';
import UniversalItemTransferComponent from './pages/universal-collections-transfer.vue';
import FileTransferComponent from './pages/file-transfer.vue';
import ChangedItemActivities from './pages/changed-items-activities.vue';
import { CHANGED_ITEMS_ACTIVITIES } from './path';

export default defineModule({
	id: 'schema-export',
	name: 'Schema export',
	icon: 'box',
	routes: [
		{
			id: 'schema-export/main',
			path: '',
			component: SchemaTransferComponent,
		},
		{
			id: 'schema-export/collection-transfer',
			path: 'collection-transfer',
			component: ItemTransferComponent,
		},
		{
			id: 'schema-export/universal-collection-transfer',
			path: 'universal-collection-transfer',
			component: UniversalItemTransferComponent,
		},
		{
			id: 'schema-export/file-transfer',
			path: 'file-transfer',
			component: FileTransferComponent,
		},
		{
			id: CHANGED_ITEMS_ACTIVITIES,
			path: 'changed-items-activities',
			component: ChangedItemActivities,
		},
	],
	preRegisterCheck(user) {
		return user?.role?.admin_access === true;
	},
});
