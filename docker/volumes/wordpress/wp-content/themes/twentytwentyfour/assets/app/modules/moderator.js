export const moduleId = 'moderator';
export const title = 'Модератор';
export function getMenu(){ return [{ id:'moderation-onboarding', label:'Onboarding', route:'#/moderation/onboarding' }]; }
export function getRoutes(){ return [{ route:'#/moderation/onboarding', title:'Onboarding', render(c){ c.innerHTML='<div class="vp-card"><div class="vp-badge is-warning">moderator</div><p>Очередь онбординга (MVP заглушка).</p></div>'; } }]; }
export async function onActivate(){}
