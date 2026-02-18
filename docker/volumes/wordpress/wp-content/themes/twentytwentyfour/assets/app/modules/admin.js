export const moduleId = 'admin';
export const title = 'Админ';
export function getMenu(){ return [{ id:'admin-onboarding', label:'Onboarding', route:'#/moderation/onboarding' }]; }
export function getRoutes(){ return [{ route:'#/moderation/onboarding', title:'Onboarding', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-badge is-danger">admin</div><p>Админ-панель онбординга (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
