<script setup>
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from 'vue';
import {api, brandLogo, clearSession, go, notify, state, t, toggleLanguage, toggleTheme} from '../store';
import Icon from './Icon.vue';
import AccountMenu from './AccountMenu.vue';

const props = defineProps({authPage: Boolean});
const emit = defineEmits(['height']);
const header = ref(null), mobileTrigger = ref(null), assessmentTrigger = ref(null), assessmentMenu = ref(null);
const mobileOpen = ref(false), assessmentsOpen = ref(false);
let observer;
const label = (en, id) => state.language === 'id' ? id : en;
const logo = computed(() => state.settings.logo_media_id ? brandLogo() : '/brand/logo-dark.svg');
const sections = computed(() => [
    {id: 'home', name: label('Home', 'Beranda')},
    {id: 'about', name: label('About INBAF', 'Tentang INBAF')},
    {id: 'proficiency', name: label('Proficiency Levels', 'Tingkat Kemahiran')},
]);
const assessments = computed(() => [
    {id: 'simulation', icon: 'Play', name: label('Simulation Test', 'Tes Simulasi'), detail: label('Explore the format. Find your rhythm.', 'Kenali formatnya. Temukan ritmemu.')},
    {id: 'placement', icon: 'Compass', name: label('Placement Test', 'Tes Penempatan'), detail: label('Find the right place to begin.', 'Temukan titik awal yang tepat.')},
    {id: 'certification', icon: 'BadgeCheck', name: label('Certification Test', 'Tes Sertifikasi'), detail: label('Make your progress verifiable.', 'Buktikan perkembanganmu.')},
]);

function updateHeight() {emit('height', header.value?.getBoundingClientRect().height || 0);}
function closeMenus(restoreFocus = false) {
    const assessmentWasOpen = assessmentsOpen.value;
    assessmentsOpen.value = false;
    mobileOpen.value = false;
    if (restoreFocus) (assessmentWasOpen ? assessmentTrigger.value : mobileTrigger.value)?.focus();
}
async function toggleAssessments(focusLast = false) {
    assessmentsOpen.value = !assessmentsOpen.value;
    if (!assessmentsOpen.value) return;
    await nextTick();
    const items = assessmentMenu.value?.querySelectorAll('[role="menuitem"]');
    items?.[focusLast ? items.length - 1 : 0]?.focus();
}
function assessmentKeys(event) {
    if (event.key === 'Escape') {event.preventDefault(); event.stopPropagation(); assessmentsOpen.value = false; assessmentTrigger.value?.focus(); return;}
    if (event.key === 'Tab') {assessmentsOpen.value = false; return;}
    const items = [...(assessmentMenu.value?.querySelectorAll('[role="menuitem"]') || [])];
    if (!items.length) return;
    let index = items.indexOf(document.activeElement);
    if (event.key === 'ArrowDown') index = (index + 1) % items.length;
    else if (event.key === 'ArrowUp') index = (index - 1 + items.length) % items.length;
    else if (event.key === 'Home') index = 0;
    else if (event.key === 'End') index = items.length - 1;
    else return;
    event.preventDefault(); items[index]?.focus();
}
async function navigateSection(event, id) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault(); closeMenus();
    if (state.path !== '/') go('/');
    await nextTick();
    history.replaceState(history.state, '', '/#' + id);
    window.dispatchEvent(new CustomEvent('inbaf:landing-anchor', {detail: {id}}));
    await nextTick();
    const target = document.getElementById(id);
    if (!target) return;
    if (!target.matches('a[href],button,input,select,textarea,[tabindex]')) target.setAttribute('tabindex', '-1');
    target.focus({preventScroll: true});
    target.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
}
function navigate(path) {closeMenus(); go(path);}
async function logout() {
    try {await api('/logout', {method: 'POST'}); clearSession(true);}
    catch (error) {notify(error.message, true);}
}
function outside(event) {if (!header.value?.contains(event.target)) closeMenus();}
function escape(event) {if (event.key === 'Escape' && (mobileOpen.value || assessmentsOpen.value)) closeMenus(true);}
function focusOutside(event) {if (!header.value?.contains(event.target)) closeMenus();}
watch(() => state.path + state.query, () => closeMenus());
onMounted(() => {
    updateHeight();
    if (typeof ResizeObserver !== 'undefined') {observer = new ResizeObserver(updateHeight); observer.observe(header.value);}
    window.addEventListener('resize', updateHeight);
    document.addEventListener('pointerdown', outside);
    document.addEventListener('focusin', focusOutside);
    document.addEventListener('keydown', escape);
});
onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('resize', updateHeight);
    document.removeEventListener('pointerdown', outside);
    document.removeEventListener('focusin', focusOutside);
    document.removeEventListener('keydown', escape);
});
</script>

<template>
<header ref="header" class="public-header premium-public-header">
    <a class="public-skip-link" :href="state.path === '/' ? '#landing-content' : '#main-content'">{{ label('Skip to content', 'Lewati ke konten') }}</a>
    <div class="public-navigation-row">
        <a href="/#home" class="public-brand" :aria-label="label('INBAF home', 'Beranda INBAF')" @click="navigateSection($event, 'home')"><img :src="logo" class="public-logo" alt="INBAF"></a>
        <nav id="public-primary-navigation" class="public-primary-navigation" :class="{'is-open': mobileOpen}" :aria-label="label('Main navigation', 'Navigasi utama')">
            <a v-for="section in sections" :key="section.id" :href="'/#' + section.id" @click="navigateSection($event, section.id)">{{ section.name }}</a>
            <div class="public-assessments">
                <button ref="assessmentTrigger" class="public-assessment-trigger" type="button" aria-haspopup="menu" :aria-expanded="assessmentsOpen" aria-controls="public-assessment-menu" @click="toggleAssessments()" @keydown.down.prevent="!assessmentsOpen && toggleAssessments()" @keydown.up.prevent="!assessmentsOpen && toggleAssessments(true)">{{ label('Assessments', 'Pilihan Tes') }}<Icon name="ChevronDown" :size="16" :class="{'chevron-open': assessmentsOpen}"/></button>
                <div v-if="assessmentsOpen" id="public-assessment-menu" ref="assessmentMenu" class="public-assessment-menu" role="menu" :aria-label="label('Assessment types', 'Jenis tes')" @keydown="assessmentKeys">
                    <a v-for="assessment in assessments" :key="assessment.id" role="menuitem" :href="'/#' + assessment.id" :aria-label="assessment.name" :aria-describedby="'public-assessment-detail-' + assessment.id" @click="navigateSection($event, assessment.id)"><span class="public-assessment-symbol"><Icon :name="assessment.icon" :size="22"/></span><span><strong>{{ assessment.name }}</strong><small :id="'public-assessment-detail-' + assessment.id">{{ assessment.detail }}</small></span><Icon name="ArrowUpRight" :size="17"/></a>
                </div>
            </div>
            <!-- Single Verify Certificate Link - Responsive -->
            <a href="/verify" class="public-verify-link" @click.prevent="navigate('/verify')">
                <Icon name="BadgeCheck" :size="18"/>
                {{ label('Verify Certificate', 'Verifikasi Sertifikat') }}
            </a>
            <a v-if="state.user" href="/dashboard" @click.prevent="navigate('/dashboard')"><Icon name="LayoutDashboard" :size="18"/>Dashboard</a>
        </nav>
        <div class="public-actions">
            <span class="public-control-divider" aria-hidden="true"></span>
            <button type="button" class="language-button" :aria-label="label('Change language. Current language: English', 'Ganti bahasa. Bahasa saat ini: Indonesia')" @click="toggleLanguage"><Icon name="Globe2" :size="18"/>{{ state.language.toUpperCase() }}</button>
            <button type="button" class="icon-button public-theme-toggle" @click="toggleTheme" :aria-label="t('theme')" :aria-pressed="state.dark"><Icon :name="state.dark ? 'Sun' : 'Moon'" :size="20"/></button>
            <AccountMenu v-if="state.user" @logout="logout"/>
            <a v-else href="/login" class="public-sign-in" :aria-current="authPage && state.path === '/login' ? 'page' : undefined" @click.prevent="navigate('/login')">{{ t('login') }}<Icon name="ArrowUpRight" :size="18"/></a>
        </div>
        <button ref="mobileTrigger" type="button" class="public-menu-toggle" :aria-label="mobileOpen ? label('Close navigation', 'Tutup navigasi') : label('Open navigation', 'Buka navigasi')" :aria-expanded="mobileOpen" aria-controls="public-primary-navigation" @click="mobileOpen = !mobileOpen; assessmentsOpen = false"><Icon :name="mobileOpen ? 'X' : 'Menu'" :size="24"/></button>
    </div>
</header>
</template>

<style scoped>
:root .public-header.premium-public-header {
    position: sticky;
    top: 0;
    z-index: 90;
    display: block;
    min-height: 0;
    max-width: none;
    width: 100%;
    margin: 0;
    padding: 0.9rem clamp(1rem, 3.4vw, 3.5rem);
    background: #092522;
    color: #E8F1EB;
    border-bottom: 1px solid rgba(190, 247, 143, 0.12);
    box-shadow: 0 8px 30px rgba(2, 21, 20, 0.15);
    isolation: isolate;
}
.public-navigation-row{max-width:1440px;margin-inline:auto;display:flex;align-items:center;gap:clamp(1rem,2vw,2rem);min-height:3rem;position:relative;min-width:0}
.public-brand{display:flex;align-items:center;min-height:44px;flex-shrink:0}
:root .premium-public-header .public-logo{display:block;width:8.5rem;max-width:160px;height:auto}
:root .premium-public-header .public-primary-navigation{display:flex;align-items:center;justify-content:center;gap:.25rem 1rem;margin-inline:auto;min-width:0;flex-wrap:wrap}
:root .premium-public-header .public-primary-navigation>a,:root .premium-public-header .public-assessment-trigger{display:inline-flex;align-items:center;gap:.4rem;min-height:44px;padding:.45rem 0;color:#f0f5eb;font-size:1.0625rem;line-height:1.5;font-weight:550;white-space:nowrap;transition:color .16s}
:root .premium-public-header .public-primary-navigation>a:hover,:root .premium-public-header .public-assessment-trigger:hover{color:#c8f69b}
.public-assessments{position:relative}
.public-assessment-trigger>svg{transition:transform .18s}
.chevron-open{transform:rotate(180deg)}
.public-assessment-menu{position:absolute;top:calc(100% + 1rem);left:50%;transform:translateX(-50%);width:25rem;max-width:calc(100vw - 2rem);padding:.5rem;background:#f4f5e9;border:1px solid #dbe6d8;border-radius:1rem;box-shadow:0 18px 54px #0215143b;color:#153333;z-index:2}
:root .premium-public-header .public-assessment-menu>a{display:flex;align-items:center;gap:.8rem;min-height:4.7rem;padding:.9rem .7rem;border-radius:.6rem;color:#173a32;font-size:1.0625rem;line-height:1.5}
.public-assessment-menu>a:hover,.public-assessment-menu>a:focus-visible{background:#e4ebdc}
.public-assessment-symbol{display:flex;align-items:center;justify-content:center;width:2.6rem;height:2.6rem;border:1px solid #c4d4c2;border-radius:.7rem;flex-shrink:0}
.public-assessment-menu a>span:nth-child(2){min-width:0;flex:1}
.public-assessment-menu strong{display:block;font-size:1.0625rem;font-weight:650}
.public-assessment-menu small{display:block;margin-top:.2rem;font-size:.9375rem;line-height:1.55;color:#52665b}
:root .premium-public-header .public-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:nowrap;flex-basis:auto;gap:.45rem;margin:0;flex-shrink:0}
.public-verify-link{display:flex;align-items:center;gap:.35rem;min-height:44px;color:#f0f5eb;font-size:1.0625rem;line-height:1.5;font-weight:550;white-space:nowrap}
.public-verify-link:hover{color:#c8f69b}
.public-control-divider{width:1px;height:1.4rem;background:#e3ffe026;margin-inline:.55rem}
:root .premium-public-header .language-button{min-height:44px;min-width:44px;padding:.4rem .3rem;color:#eff8ec;font-size:1.0625rem;line-height:1.45;gap:.35rem;border-radius:.5rem}
:root .premium-public-header .icon-button.public-theme-toggle{width:44px;height:44px;min-width:44px;min-height:44px;color:#eff8ec;border-radius:.5rem}
:root .premium-public-header .language-button:hover,:root .premium-public-header .icon-button.public-theme-toggle:hover{background:#c8f69b15;color:#c8f69b}
.public-sign-in{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:46px;padding:.6rem .9rem;margin-left:.3rem;border:1px solid #c8f69b75;border-radius:3rem;background:#c8f69b;color:#16332a;font-size:1.0625rem;line-height:1.45;font-weight:650;white-space:nowrap;transition:background .18s,box-shadow .18s}
.public-sign-in:hover{background:#dcffbc;box-shadow:0 0 22px #c8f69b1f}
.public-menu-toggle {
    display: none;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    min-height: 44px;
    border: 1px solid rgba(190, 247, 143, 0.2);
    border-radius: 0.65rem;
    color: #E8F1EB;
}
/* Verify link styling - single unified element */
.public-verify-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.9rem;
    font-size: 0.9375rem;
    font-weight: 550;
    color: #BEF78F;
    border-radius: 2rem;
    transition: all 0.2s;
}
.public-verify-link:hover {
    background: rgba(190, 247, 143, 0.1);
    color: #A9E879;
}
:root .premium-public-header a:focus-visible,:root .premium-public-header button:focus-visible{outline:3px solid #c8f69b;outline-offset:4px}
.public-skip-link{position:absolute;top:.5rem;left:1rem;z-index:5;transform:translateY(-180%);padding:.7rem 1rem;background:#c8f69b;color:#16332a;border-radius:.5rem;font-size:1.0625rem;font-weight:650;line-height:1.5}
.public-skip-link:focus{transform:translateY(0)}
.public-actions :deep(.user-button){color:#eff8ec;min-height:44px}
.public-actions :deep(.user-label){display:none}
.public-actions :deep(.account-avatar){background:#c8f69b;color:#17332a}
.public-actions :deep(.account-dropdown){top:calc(100% + 1rem);z-index:100}
@media(min-width:1280px){.public-navigation-row{gap:1.2rem}}
@media (max-width: 1279px) {
    :root .premium-public-header .public-primary-navigation {
        display: none;
        position: absolute;
        top: calc(100% + 0.9rem);
        left: 0;
        right: 0;
        margin: 0;
        padding: 1rem;
        background: #092522;
        border: 1px solid rgba(190, 247, 143, 0.2);
        border-radius: 0 0 1rem 1rem;
        box-shadow: 0 24px 40px rgba(2, 21, 20, 0.4);
        max-height: calc(100dvh - var(--public-header-height, 7rem) - 1rem);
        overflow-y: auto;
        overscroll-behavior: contain;
        order: unset;
        width: auto;
    }
    :root .premium-public-header .public-primary-navigation.is-open {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 0.3rem;
    }
    :root .premium-public-header .public-primary-navigation > a,
    :root .premium-public-header .public-assessment-trigger {
        width: 100%;
        padding: 0.7rem 0.6rem;
        font-size: 1.0625rem;
        white-space: normal;
    }
    .public-assessment-trigger { justify-content: space-between; }
    .public-assessment-menu {
        position: static;
        width: 100%;
        max-width: none;
        margin: 0.5rem 0;
        transform: none;
        border-radius: 0.6rem;
        box-shadow: none;
    }
    .public-menu-toggle { display: flex; flex-shrink: 0; }
    :root .premium-public-header .public-actions { margin-left: auto; }
    /* Hide verify link in actions on mobile since it's now in nav */
    .public-actions .public-verify-link { display: none; }
    .public-control-divider { display: none; }
}
@media(max-width:600px){
    :root .public-header.premium-public-header{padding:.7rem 1rem}
    .public-navigation-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.45rem .7rem}
    :root .premium-public-header .public-logo{width:8rem;max-width:145px}
    .public-menu-toggle{grid-column:2;grid-row:1}
    :root .premium-public-header .public-actions{grid-column:1/-1;grid-row:2;justify-content:flex-start;gap:.35rem;margin:0;min-width:0}
    .public-sign-in{margin-left:auto;padding:.55rem .9rem;white-space:normal}
    .public-actions :deep(.account-menu){margin-left:auto}
    .public-actions :deep(.account-dropdown){position:fixed;top:calc(var(--public-header-height,8rem) + .4rem);right:12px;max-height:calc(100dvh - var(--public-header-height,8rem) - 1rem)}
    .public-assessment-menu strong{font-size:1.0625rem}
    .public-assessment-menu a>svg:last-child{display:none}
    .public-assessment-symbol{width:2.2rem;height:2.2rem}
}
@media(prefers-reduced-motion:reduce){.public-assessment-trigger>svg,.public-sign-in,:root .premium-public-header .public-primary-navigation>a,:root .premium-public-header .public-assessment-trigger{transition:none}}
</style>
