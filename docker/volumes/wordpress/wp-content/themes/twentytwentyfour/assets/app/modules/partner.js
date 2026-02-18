export const moduleId = 'partner';
export const title = 'Партнёр';
export function getMenu(){ return [{ id:'partner-tools', label:'Tools', route:'#/partner/tools' }]; }
export function getRoutes(){ return [{ route:'#/partner/tools', title:'Tools', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-badge">partner</div><p>Партнёрские инструменты (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
