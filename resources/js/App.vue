<script setup>
import { computed,ref,onMounted,onBeforeUnmount,watch,nextTick } from 'vue';
import { state,t,go,api,brandLogo,bootstrap,admin,toggleTheme,toggleLanguage,notify,clearSession } from './store';
import Icon from './components/Icon.vue';
import TextSizeControl from './components/TextSizeControl.vue';
import PublicPages from './components/PublicPages.vue';
import Dashboard from './components/Dashboard.vue';
import ParticipantPages from './components/ParticipantPages.vue';
import AdminWorkspace from './components/AdminWorkspace.vue';
import ExamWorkspace from './components/ExamWorkspace.vue';
import ArchitectureDiagrams from './components/ArchitectureDiagrams.vue';
import AccountMenu from './components/AccountMenu.vue';
import AccountProfile from './components/AccountProfile.vue';
import UserManagement from './components/UserManagement.vue';
import SetPasswordPage from './components/SetPasswordPage.vue';
import NotificationPanel from './components/NotificationPanel.vue';
const menuOpen=ref(false),notifications=ref(false),checkingSession=ref(false);
const sidebar=ref(null),menuButton=ref(null),mainContent=ref(null),notificationArea=ref(null);
const mobileNavigation=ref(matchMedia('(max-width: 1100px)').matches),collapsed=ref(false);
const navigationMedia=matchMedia('(max-width: 1100px)');
let navigationOverflow='',navigationPath='';
const publicPage=computed(()=>['/','/login','/register','/set-password'].includes(state.path)||state.path.startsWith('/verify'));
const examPage=computed(()=>state.path.startsWith('/exam/'));
const pageTitle=computed(()=>{
 const parts=state.path.split('/').filter(Boolean),key=parts[0]==='admin'?parts[1]:parts[0];
 if(parts.length>1&&parts[0]==='tests')return state.language==='id'?'Persiapan ujian':'Assessment readiness';
 if(parts.length>1&&parts[0]==='results')return state.language==='id'?'Detail hasil':'Result details';
 if(key==='architecture')return state.language==='id'?'Arsitektur & alur tes':'Architecture & test flows';
 return t(key||'dashboard');
});
const nav=computed(()=>admin.value?[['dashboard','LayoutDashboard','/dashboard'],['users','UserRoundCog','/admin/users'],['questions','LibraryBig','/admin/questions'],['packages','Layers','/admin/packages'],['participants','UsersRound','/admin/participants'],['reviews','ClipboardCheck','/admin/reviews'],['certificates','BadgeCheck','/admin/certificates']]:[['dashboard','LayoutDashboard','/dashboard'],['tests','BookOpen','/tests'],['results','ChartNoAxesCombined','/results'],['certificates','BadgeCheck','/certificates']]);
const management=[['competencies','Shapes','/admin/competencies'],['groups','BookOpenText','/admin/groups'],['media','Images','/admin/media'],['levels','ChartNoAxesColumnIncreasing','/admin/levels'],['settings','Settings2','/admin/settings'],['audit','ScrollText','/admin/audit']];
async function logout(){try{await api('/logout',{method:'POST'});clearSession(true)}catch(e){notify(e.message,true)}}
async function restoreSession(event){if(!event.persisted)return;checkingSession.value=true;try{await bootstrap();if(!state.user&&!publicPage.value)clearSession()}catch(e){clearSession();notify(e.message,true)}finally{checkingSession.value=false}}
function sessionEvent(event){if(event.key!=='inbaf-auth-event')return;try{if(JSON.parse(event.newValue)?.type==='logout')clearSession()}catch{}}
function closeNavigation(){menuOpen.value=false}
function navigationChanged(event){mobileNavigation.value=event.matches;closeNavigation()}
function shellKeydown(event){
 if(event.key==='Escape'){if(menuOpen.value){event.preventDefault();closeNavigation()}notifications.value=false;return}
 if(event.key!=='Tab'||!menuOpen.value||!mobileNavigation.value)return;
 const controls=[...sidebar.value.querySelectorAll('a[href],button:not([disabled])')].filter(el=>el.getClientRects().length);
 const first=controls[0],last=controls.at(-1);
 if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus()}
 else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus()}
}
function shellPointer(event){if(!notificationArea.value?.contains(event.target))notifications.value=false}
watch(menuOpen,async(open)=>{
 if(open&&mobileNavigation.value){navigationPath=state.path;navigationOverflow=document.body.style.overflow;document.body.style.overflow='hidden';await nextTick();sidebar.value?.querySelector('button,a[href]')?.focus()}
 else {document.body.style.overflow=navigationOverflow;if(mobileNavigation.value&&state.path===navigationPath)await nextTick().then(()=>menuButton.value?.focus())}
});
watch(()=>state.path,async()=>{menuOpen.value=false;notifications.value=false;if(state.ready&&!state.user&&!publicPage.value)go('/login');await nextTick();mainContent.value?.focus({preventScroll:true})});
onMounted(async()=>{try{await bootstrap();if(!state.user&&!publicPage.value)go('/login');else if(state.user?.must_change_password&&!publicPage.value)go('/profile?tab=password&required=1')}catch(e){state.ready=true;notify(e.message,true)}});
onMounted(()=>{addEventListener('pageshow',restoreSession);addEventListener('storage',sessionEvent);addEventListener('keydown',shellKeydown);addEventListener('pointerdown',shellPointer);navigationMedia.addEventListener('change',navigationChanged)});
onBeforeUnmount(()=>{removeEventListener('pageshow',restoreSession);removeEventListener('storage',sessionEvent);removeEventListener('keydown',shellKeydown);removeEventListener('pointerdown',shellPointer);navigationMedia.removeEventListener('change',navigationChanged);if(menuOpen.value)document.body.style.overflow=navigationOverflow});
</script>
<template>
<div v-if="!state.ready||checkingSession" class="initial-loader"><img src="/brand/logo-light.svg" alt="INBAF"><span class="loader"></span><p>{{t('loading')}}</p></div>
<template v-else>
<SetPasswordPage v-if="state.path==='/set-password'"/>
<PublicPages v-else-if="publicPage" />
<ExamWorkspace v-else-if="examPage" :key="state.path" />
<div v-else class="workspace" :class="{'sidebar-collapsed':collapsed&&!mobileNavigation}">
<a class="skip-link" href="#main-content">{{state.language==='id'?'Lewati ke konten':'Skip to content'}}</a>
<button v-if="menuOpen" class="sidebar-backdrop" @click="menuOpen=false" aria-label="Close navigation"></button>
<aside id="workspace-navigation" ref="sidebar" class="sidebar" :class="{open:menuOpen}" :inert="mobileNavigation&&!menuOpen" :aria-hidden="mobileNavigation&&!menuOpen?true:undefined" :role="mobileNavigation&&menuOpen?'dialog':undefined" :aria-modal="mobileNavigation&&menuOpen?true:undefined" :aria-label="t('menu')==='menu'?'Navigation':t('menu')">
<button class="icon-button sidebar-close" :aria-label="state.language==='id'?'Tutup navigasi':'Close navigation'" @click="closeNavigation"><Icon name="X"/></button>
<a href="/dashboard" class="sidebar-brand" @click.prevent="go('/dashboard')"><img :src="collapsed&&!mobileNavigation?'/brand/favicon.svg':state.settings.logo_media_id?brandLogo():'/brand/logo-dark.svg'" alt="INBAF"><span>{{t('platform')}}</span></a>
<p class="nav-caption">{{admin?t('administration'):t('learning')}}</p>
<nav :aria-label="admin?t('administration'):t('learning')"><a v-for="item in nav" :key="item[0]" :href="item[2]" :title="t(item[0])" :aria-label="t(item[0])" :aria-current="state.path===item[2]||state.path.startsWith(item[2]+'/')?'page':undefined" :class="{selected:state.path===item[2]||state.path.startsWith(item[2]+'/')}" @click.prevent="go(item[2])"><Icon :name="item[1]"/><span>{{t(item[0])}}</span><span v-if="item[0]==='tests'" class="nav-pill">{{state.packages.length}}</span><span v-if="state.path===item[2]" class="active-dot"></span></a></nav>
<template v-if="admin"><p class="nav-caption secondary-caption">WORKSPACE</p><nav :aria-label="state.language==='id'?'Pengelolaan':'Management'"><a v-for="item in management" :key="item[0]" :href="item[2]" :title="t(item[0])" :aria-label="t(item[0])" :aria-current="state.path===item[2]?'page':undefined" :class="{selected:state.path===item[2]}" @click.prevent="go(item[2])"><Icon :name="item[1]"/><span>{{t(item[0])}}</span></a></nav></template>
<div v-else class="sidebar-help"><span class="help-icon"><Icon name="Sparkles" :size="21"/></span><h4>{{t('help')}}</h4><p>{{t('helpText')}}</p><button @click="go('/tests')">{{t('browse')}} <Icon name="ArrowUpRight" :size="16"/></button><span class="help-orbit"></span></div>
<div class="sidebar-bottom"><a href="/profile" :aria-label="t('profile')" :title="t('profile')" :aria-current="state.path==='/profile'?'page':undefined" @click.prevent="go('/profile')" :class="{selected:state.path==='/profile'}"><Icon name="CircleUserRound"/><span>{{t('profile')}}</span></a><button :aria-label="t('logout')" :title="t('logout')" @click="logout"><Icon name="LogOut"/><span>{{t('logout')}}</span></button><div class="sidebar-version"><span class="tiny-dot"></span> INBAF Assessment Platform <span>v1.0</span></div></div>
</aside>
<div class="main-frame" :inert="mobileNavigation&&menuOpen">
<header class="topbar"><div class="breadcrumb"><button ref="menuButton" class="icon-button mobile-menu" @click="menuOpen=true" aria-label="Open navigation" aria-controls="workspace-navigation" :aria-expanded="menuOpen"><Icon name="Menu"/></button><button class="icon-button desktop-menu" @click="collapsed=!collapsed" :aria-label="collapsed?'Expand navigation':'Collapse navigation'" :aria-expanded="!collapsed" aria-controls="workspace-navigation"><Icon :name="collapsed?'PanelLeftOpen':'PanelLeftClose'"/></button><a href="/dashboard" @click.prevent="go('/dashboard')">{{state.language==='id'?'Ruang kerja':'Workspace'}}</a><Icon name="ChevronRight" :size="16"/><strong aria-current="page">{{pageTitle}}</strong></div><div class="topbar-actions"><a href="/" class="button secondary small portal-home-link" @click.prevent="go('/')"><Icon name="Globe2" :size="18"/>{{state.language==='id'?'Beranda Portal':'Portal Home'}}</a><TextSizeControl compact/><button class="language-button" :aria-label="state.language==='en'?'Change language to Indonesian':'Ubah bahasa ke Inggris'" @click="toggleLanguage"><Icon name="Globe2" :size="16"/>{{state.language==='en'?'EN':'ID'}}</button><button class="icon-button" :aria-label="t('theme')" @click="toggleTheme"><Icon :name="state.dark?'Sun':'Moon'" :size="19"/></button><div ref="notificationArea" class="notification-wrap"><button class="icon-button" :aria-label="t('notifications')" :aria-expanded="notifications" aria-controls="workspace-notifications" @click="notifications=!notifications"><Icon name="Bell" :size="20"/></button><div v-if="notifications" id="workspace-notifications" class="notification-popover" role="region" :aria-label="t('notifications')"><NotificationPanel @navigate="notifications=false"/></div></div><span class="topbar-divider"></span><AccountMenu @logout="logout"/></div></header>
<main id="main-content" ref="mainContent" class="main-content" tabindex="-1"><ArchitectureDiagrams v-if="state.path==='/architecture'"/><Dashboard v-else-if="state.path==='/dashboard'"/><UserManagement v-else-if="state.path==='/admin/users'&&admin"/><AccountProfile v-else-if="state.path==='/profile'||state.path==='/admin/change-password'"/><AdminWorkspace v-else-if="state.path.startsWith('/admin/')" :key="state.path"/><ParticipantPages v-else :key="state.path"/></main>
<footer class="app-footer"><a href="/architecture" @click.prevent="go('/architecture')"><Icon name="Layers" :size="17"/>{{state.language==='id'?'Arsitektur & alur tes':'Architecture & test flows'}}</a><span>© {{new Date().getFullYear()}} {{state.settings.system_name||'INBAF'}}. {{state.settings.footer||t('footer')}}.</span><span><Icon name="ShieldCheck" :size="14"/>{{t('secure')}}</span></footer>
</div></div>
</template>
<div class="toast" v-if="state.notice" role="status"><Icon name="CircleCheck"/>{{state.notice}}</div><div class="toast error-toast" v-if="state.error" role="alert"><Icon name="CircleAlert"/>{{state.error}}<button class="icon-button" @click="state.error=''" aria-label="Dismiss"><Icon name="X" :size="16"/></button></div>
</template>
