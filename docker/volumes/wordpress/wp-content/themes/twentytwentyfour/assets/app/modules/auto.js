export const moduleId = 'auto';
export const title = 'Авто';
export function getMenu(){ return [{ id:'auto-orders', label:'Orders', route:'#/auto/orders' }]; }
export function getRoutes(){ return [{ route:'#/auto/orders', title:'Orders', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-badge">auto</div><p>Список заказов (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
