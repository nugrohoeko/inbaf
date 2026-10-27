import { reactive, computed } from 'vue';
import en from './locales/en';
import id from './locales/id';
const textSizes=['small','normal','large','extra_large'];
function storedTextSize(){try{const value=localStorage.getItem('inbaf-text-size');return textSizes.includes(value)?value:'normal'}catch{return 'normal'}}
export const state = reactive({user:null,settings:{},competencies:[],levels:[],packages:[],question_types:[],test_types:[],ready:false,path:location.pathname,query:location.search||'',language:localStorage.getItem('inbaf-language')||'en',dark:localStorage.getItem('inbaf-theme')==='dark',textSize:storedTextSize(),textSizeSaving:false,languageSaving:false,notice:'',error:''});
export function applyTextSize(value){state.textSize=textSizes.includes(value)?value:'normal';document.documentElement.dataset.textSize=state.textSize;try{localStorage.setItem('inbaf-text-size',state.textSize)}catch{}}
applyTextSize(state.textSize);
let preferenceRevision=0,preferenceQueue=Promise.resolve(),authenticationRevision=0,languageRevision=0,languageQueue=Promise.resolve(),languagePending=0;
export function applyLanguage(value){if(!['id','en'].includes(value))return;languageRevision++;state.language=value;document.documentElement.lang=value;try{localStorage.setItem('inbaf-language',value)}catch{}}
export async function setLanguage(value){
 if(!['id','en'].includes(value))return;
 applyLanguage(value);
 const revision=languageRevision,userId=state.user?.id,sessionRevision=authenticationRevision;
 if(!userId||state.user.role!=='participant')return;
 state.user.preferred_language=value;languagePending++;state.languageSaving=true;
 const save=languageQueue.then(()=>{if(state.user?.id===userId&&state.user.role==='participant'&&sessionRevision===authenticationRevision)return api('/preferences',{method:'PUT',body:{preferred_language:value}})});
 languageQueue=save.catch(()=>{});
 try{await save}catch(e){if(revision===languageRevision&&state.user?.id===userId)notify((state.language==='id'?'Bahasa diterapkan, tetapi belum tersimpan ke akun.':'The language is applied, but could not be saved to your account.')+' '+e.message,true)}finally{languagePending--;if(state.user?.id===userId&&sessionRevision===authenticationRevision)state.languageSaving=languagePending>0}
}
export async function setTextSize(value){
 if(!textSizes.includes(value))return;
 applyTextSize(value);
 const revision=++preferenceRevision,userId=state.user?.id;
 if(!userId)return;
 state.user.text_size=value;state.textSizeSaving=true;
 // Keep rapid changes in order so an older request cannot overwrite the last choice.
 const save=preferenceQueue.then(()=>{if(state.user?.id===userId)return api('/preferences',{method:'PUT',body:{text_size:value}})});
 preferenceQueue=save.catch(()=>{});
 try{await save}catch(e){if(revision===preferenceRevision&&state.user?.id===userId)notify(t('textSizeSaveFailed')+': '+e.message,true)}finally{if(revision===preferenceRevision)state.textSizeSaving=false}
}
export const t=(key)=>({en,id}[state.language]?.[key]||en[key]||key);
export function notify(message,isError=false){state[isError?'error':'notice']=message;setTimeout(()=>state[isError?'error':'notice']='',6000)}
export function go(path){let url=new URL(path,location.origin);if(state.user?.must_change_password&&!['/profile','/set-password'].includes(url.pathname))url=new URL('/profile?tab=password&required=1',location.origin);if(url.pathname===state.path&&url.search===state.query)return;history.pushState({},'',url.pathname+url.search+url.hash);state.path=url.pathname;state.query=url.search;window.scrollTo({top:0,behavior:'instant'})}
addEventListener('popstate',()=>{state.path=location.pathname;state.query=location.search||''});
export function clearSession(broadcast=false){authenticationRevision++;preferenceRevision++;languageRevision++;state.user=null;state.textSizeSaving=false;state.languageSaving=false;state.notice='';state.error='';if(broadcast){try{localStorage.setItem('inbaf-auth-event',JSON.stringify({type:'logout',at:Date.now(),nonce:Math.random()}))}catch{}}go('/login')}
export function toggleTheme(){state.dark=!state.dark;document.documentElement.classList.toggle('dark',state.dark);localStorage.setItem('inbaf-theme',state.dark?'dark':'light')}
export function toggleLanguage(){return setLanguage(state.language==='en'?'id':'en')}
export async function api(path,options={}){const requestedAuthenticationRevision=authenticationRevision;const headers={Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content,...options.headers};if(options.body && !(options.body instanceof FormData)){headers['Content-Type']='application/json';options.body=JSON.stringify(options.body)}const response=await fetch('/api'+path,{credentials:'same-origin',...options,headers});const data=await response.json().catch(()=>({}));if(data.csrf_token&&requestedAuthenticationRevision===authenticationRevision)document.querySelector('meta[name="csrf-token"]').content=data.csrf_token;if(!response.ok){if(([401,419].includes(response.status)||(response.status===403&&data.code==='ACCOUNT_INACTIVE'))&&state.user&&requestedAuthenticationRevision===authenticationRevision)clearSession();if(data.code==='PASSWORD_CHANGE_REQUIRED'&&state.user&&requestedAuthenticationRevision===authenticationRevision){state.user.must_change_password=true;go('/profile?tab=password&required=1')}let message=data.message||t('error');if(data.errors)message=Object.values(data.errors).flat().join(' ');const err=new Error(message);err.status=response.status;err.data=data;throw err}return data}
export async function bootstrap(){
 const requestedAuthenticationRevision=authenticationRevision,requestedPreferenceRevision=preferenceRevision,requestedLanguageRevision=languageRevision;
 const data=await api('/bootstrap');if(requestedAuthenticationRevision!==authenticationRevision)return data;
 const accountChanged=state.user?.id!==data.user?.id;
 const preserveTextSize=data.user&&!accountChanged&&(state.textSizeSaving||requestedPreferenceRevision!==preferenceRevision);
 const preserveLanguage=data.user?.role==='participant'&&!accountChanged&&(state.languageSaving||requestedLanguageRevision!==languageRevision);
 Object.assign(state,data,{ready:true});
 if(accountChanged){preferenceRevision++;languageRevision++;state.textSizeSaving=false;state.languageSaving=false}
 if(preserveTextSize)state.user.text_size=state.textSize;else if(data.user)applyTextSize(data.user.text_size);
 if(preserveLanguage)state.user.preferred_language=state.language;
 else if(data.user?.role==='participant'&&['id','en'].includes(data.user.preferred_language))applyLanguage(data.user.preferred_language);
 else if(!localStorage.getItem('inbaf-language'))applyLanguage(data.settings?.default_locale||'en');
 document.documentElement.lang=state.language;document.title=(data.settings?.system_name||'INBAF')+' · Indonesian Language Assessment';
 document.documentElement.classList.toggle('dark',state.dark);document.documentElement.style.setProperty('--institution-accent',data.settings?.primary_color||'#193C31');
 const icon=document.querySelector('link[rel="icon"]');if(icon){icon.href=data.settings?.favicon_media_id?'/api/branding/favicon':'/brand/favicon.svg';icon.removeAttribute('type')}return data
}
export const admin=computed(()=>state.user?.role==='superadmin');
export const list=(value)=>Array.isArray(value)?value:Array.isArray(value?.data)?value.data:['packages','results','certificates','questions','items'].map(k=>value?.[k]).find(Array.isArray)||[];
export const human=(text)=>String(text||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
export const date=(value)=>value?new Date(value).toLocaleDateString(state.language==='id'?'id-ID':'en-GB',{day:'numeric',month:'short',year:'numeric'}):'—';
export {sanitize} from './security/sanitize';

export const brandLogo=()=>state.settings.logo_media_id?'/api/branding/logo':state.dark?'/brand/logo-dark.svg':'/brand/logo-light.svg';

export const testTypeCodes=['practice','placement','certification'];
export function testTypeCode(item){const value=item?.test_type??item?.test_type_code??item?.package?.test_type??item?.attempt?.test_type;return typeof value==='object'?value?.code:value||'certification'}
export function testTypeName(code){const type=state.test_types.find(row=>row.code===code);return (state.language==='en'?type?.name_en:type?.name)||t(code+'Title')}
export function testPolicy(item){return item?.effective_policy||item?.policy||item?.rules||state.test_types.find(row=>row.code===testTypeCode(item))||{}}
export function testDuration(item){return testPolicy(item).timer_enabled===false?t('untimed'):item?.duration_minutes?item.duration_minutes+' '+t('duration'):t('timed')}
