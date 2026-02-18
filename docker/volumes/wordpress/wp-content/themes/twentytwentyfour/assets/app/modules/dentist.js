export const moduleId = 'dentist';
export const title = 'Стоматолог';
export function getMenu(){ return [{ id:'dentist-cases', label:'Cases', route:'#/dentist/cases' }]; }
export function getRoutes(){ return [{ route:'#/dentist/cases', title:'Cases', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-toolbar"><div class="vp-toolbar-left"><span class="vp-badge">dentist</span></div></div><p>Раздел кейсов (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
