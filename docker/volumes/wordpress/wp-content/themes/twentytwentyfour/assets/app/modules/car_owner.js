export const moduleId = 'car_owner';
export const title = 'Владелец авто';
export function getMenu(){ return [{ id:'car-owner-cars', label:'My cars', route:'#/car_owner/cars' }]; }
export function getRoutes(){ return [{ route:'#/car_owner/cars', title:'My cars', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-badge">car_owner</div><p>Мои автомобили (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
