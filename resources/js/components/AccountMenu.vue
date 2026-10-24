<script setup>
import {computed, ref, watch, onMounted, onBeforeUnmount, nextTick} from 'vue';
import {state, go, t} from '../store';
import Icon from './Icon.vue';

const emit = defineEmits(['logout']);
const root = ref(null), trigger = ref(null), menu = ref(null), open = ref(false), photoFailed = ref(false);
const dropdownTop = ref(88);
const initials = computed(() => (state.user?.name || 'INBAF').trim().split(/\s+/).map(word => Array.from(word)[0]).slice(0, 2).join('').toUpperCase());
const label = (id, en) => state.language === 'id' ? id : en;
const participant = computed(() => state.user?.role === 'participant');
const roleLabel = computed(() => participant.value ? 'Participant' : 'Superadmin');
const menuId = computed(() => participant.value ? 'participant-account-menu' : 'superadmin-account-menu');
watch(() => state.user?.avatar_url, () => {photoFailed.value = false;});
watch(() => state.path + state.query, () => {open.value = false;});

function close(restoreFocus = false) {
    open.value = false;
    if (restoreFocus) trigger.value?.focus();
}
async function toggle(focusLast = false) {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        positionDropdown();
        const items = menu.value?.querySelectorAll('[role="menuitem"]');
        items?.[focusLast ? items.length - 1 : 0]?.focus();
    }
}
function positionDropdown() {
    if (trigger.value) dropdownTop.value = Math.max(8, Math.min(trigger.value.getBoundingClientRect().bottom + 12, window.innerHeight - 160));
}
function navigate(path) {close(); go(path);}
function outside(event) {if (!root.value?.contains(event.target)) close();}
function keydown(event) {
    if (event.key === 'Escape') {event.preventDefault(); close(true); return;}
    if (event.key === 'Tab') {close(true); return;}
    const items = [...(menu.value?.querySelectorAll('[role="menuitem"]') || [])];
    let index = items.indexOf(document.activeElement);
    if (event.key === 'ArrowDown') index = (index + 1) % items.length;
    else if (event.key === 'ArrowUp') index = (index - 1 + items.length) % items.length;
    else if (event.key === 'Home') index = 0;
    else if (event.key === 'End') index = items.length - 1;
    else return;
    event.preventDefault(); items[index]?.focus();
}
onMounted(() => {document.addEventListener('pointerdown', outside); window.addEventListener('resize', positionDropdown);});
onBeforeUnmount(() => {document.removeEventListener('pointerdown', outside); window.removeEventListener('resize', positionDropdown);});
</script>

<template>
<div ref="root" class="account-menu" @keydown.esc.prevent="close(true)">
    <button ref="trigger" class="user-button" type="button" :aria-label="label('Menu akun', 'Account menu')" aria-haspopup="menu" :aria-expanded="open" :aria-controls="menuId" @click="toggle()" @keydown.down.prevent="!open && toggle()" @keydown.up.prevent="!open && toggle(true)">
        <span class="avatar account-avatar"><img v-if="state.user?.avatar_url && !photoFailed" :src="state.user.avatar_url" alt="" @error="photoFailed = true"><span v-else>{{ initials }}</span></span>
        <span class="user-label"><strong>{{ state.user?.name }}</strong><small>{{ roleLabel }}</small></span>
        <Icon name="ChevronDown" :size="16" :class="{'account-chevron-open': open}"/>
    </button>
    <div v-if="open" :id="menuId" ref="menu" class="account-dropdown" :style="{'--account-menu-top':dropdownTop+'px'}" role="menu" :aria-label="participant ? label('Menu akun peserta', 'Participant account menu') : label('Menu akun Superadmin', 'Superadmin account menu')" @keydown="keydown">
        <div class="account-dropdown-heading" role="presentation">
            <span class="avatar account-avatar"><img v-if="state.user?.avatar_url && !photoFailed" :src="state.user.avatar_url" alt="" @error="photoFailed = true"><span v-else>{{ initials }}</span></span>
            <div><strong>{{ state.user?.name }}</strong><span>{{ roleLabel }}</span><small>{{ state.user?.email }}</small></div>
        </div>
        <a role="menuitem" href="/profile" @click.prevent="navigate('/profile')"><Icon name="CircleUserRound" :size="20"/>{{ label('Profil Saya', 'My Profile') }}<Icon name="ChevronRight" :size="16"/></a>
        <a v-if="participant" role="menuitem" href="/profile?edit=1" @click.prevent="navigate('/profile?edit=1')"><Icon name="PenLine" :size="20"/>{{ label('Edit Profil', 'Edit Profile') }}<Icon name="ChevronRight" :size="16"/></a>
        <a role="menuitem" href="/profile?tab=password" @click.prevent="navigate('/profile?tab=password')"><Icon name="KeyRound" :size="20"/>{{ label('Ubah Password', 'Change Password') }}<Icon name="ChevronRight" :size="16"/></a>
        <div class="account-menu-divider" role="separator"></div>
        <button role="menuitem" type="button" class="account-logout" @click="close(); emit('logout')"><Icon name="LogOut" :size="20"/>{{ t('logout') }}</button>
    </div>
</div>
</template>

<style scoped>
.account-menu{position:relative;min-width:0;max-width:100%}.account-menu .user-label{min-width:0;max-width:18rem}.account-menu .user-label strong{max-width:none;overflow:visible;text-overflow:clip;white-space:normal;overflow-wrap:anywhere}.account-menu>.user-button{min-height:44px}.account-avatar{overflow:hidden;flex-shrink:0;border-radius:50%;background:var(--blue-soft);color:var(--color-accent)}.account-avatar img{width:100%;height:100%;object-fit:cover;display:block}.account-menu .user-button>svg{transition:transform .18s}.account-chevron-open{transform:rotate(180deg)}.account-dropdown{position:absolute;right:0;top:calc(100% + 12px);width:min(340px,calc(100vw - 24px));background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:8px;z-index:75;animation:account-menu-in .16s ease-out}.account-dropdown-heading{display:flex;gap:12px;padding:14px 10px 18px;align-items:center;border-bottom:1px solid var(--border);margin-bottom:6px}.account-dropdown-heading>.avatar{width:46px;height:46px;display:flex;align-items:center;justify-content:center}.account-dropdown-heading>div{min-width:0}.account-dropdown-heading strong,.account-dropdown-heading span,.account-dropdown-heading small{display:block;overflow-wrap:anywhere}.account-dropdown-heading strong{color:var(--heading);font-weight:650}.account-dropdown-heading span,.account-dropdown-heading small{color:var(--muted);line-height:1.6}.account-dropdown a,.account-dropdown>button{display:flex;align-items:center;gap:12px;width:100%;min-height:48px;border-radius:10px;padding:10px 12px;text-align:left;font:inherit;color:var(--text);transition:background .15s,color .15s}.account-dropdown a>svg:first-child{color:var(--color-accent)}.account-dropdown a>svg:last-child{margin-left:auto;color:var(--muted)}.account-dropdown a:hover,.account-dropdown a:focus-visible,.account-dropdown>button:hover,.account-dropdown>button:focus-visible{background:var(--surface-alt)}.account-menu-divider{border-top:1px solid var(--border);margin:6px 2px}.account-dropdown>.account-logout{color:var(--danger,var(--color-danger))}.account-logout>svg{color:var(--danger,var(--color-danger))}@keyframes account-menu-in{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}@media(prefers-reduced-motion:reduce){.account-dropdown{animation:none}.account-menu .user-button>svg{transition:none}}@media(max-width:600px){.account-dropdown{position:fixed;right:12px;top:76px;max-height:calc(100dvh - 88px);overflow-y:auto}}
.account-avatar{background:var(--blue-soft);color:var(--color-accent)}.account-dropdown{box-shadow:var(--shadow);max-height:min(640px,calc(100dvh - 112px));overflow-y:auto;overscroll-behavior:contain}.account-dropdown-heading strong{font-size:1.125rem;line-height:1.5}.account-dropdown-heading :is(span,small){font-size:1rem;line-height:1.6}.account-dropdown :is(a,button){font-size:1.125rem;line-height:1.6;white-space:normal;overflow-wrap:anywhere;align-items:flex-start;min-height:48px}.account-dropdown a>svg{margin-top:4px}.account-dropdown a>svg:first-child{color:var(--color-accent)}.account-dropdown>.account-logout,.account-logout>svg{color:var(--danger,var(--color-danger))}.account-menu :is(a,button):focus-visible{outline:3px solid var(--color-accent);outline-offset:2px}.account-menu>.user-button{min-height:44px;max-width:100%}
@media(max-width:600px){.account-dropdown{top:var(--account-menu-top,88px);max-height:calc(100dvh - var(--account-menu-top,88px) - 12px);right:12px;width:min(340px,calc(100% - 24px));max-width:calc(100% - 24px)}.account-dropdown-heading{padding:12px 8px 16px}.account-dropdown :is(a,button){padding:12px 8px;gap:8px}}
.account-menu .user-label strong{color:var(--text)}.account-menu .user-label small{color:var(--muted)}
</style>
