export const moduleId = 'location';
export const title = 'Локация';
export function getMenu(){ return [{ id:'location-nav', label:'Navigation', route:'#/location/navigation' }]; }
export function getRoutes(){ return [{ route:'#/location/navigation', title:'Navigation', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-badge">location</div><p>Навигация по локации (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
