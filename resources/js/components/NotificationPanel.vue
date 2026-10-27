<script setup>
import {onMounted,onBeforeUnmount,ref} from 'vue';
import {api,state,t,go} from '../store';
const emit=defineEmits(['navigate']);
const items=ref([]),loading=ref(true),error=ref('');
let mounted=true;
const labels={
    admin_reviews:['Jawaban menunggu penilaian','Answers awaiting review'],admin_active:['Ujian sedang berlangsung','Assessments in progress'],
    in_progress:['Lanjutkan ujian','Resume assessment'],pending_review:['Jawaban menunggu penilaian','Assessment awaiting review'],completed:['Hasil ujian tersedia','Assessment result available'],
    certificate_valid:['Sertifikat tersedia','Certificate available'],certificate_expired:['Sertifikat kedaluwarsa','Certificate expired'],certificate_revoked:['Sertifikat dicabut','Certificate revoked'],
};
function label(item){return labels[item.type]?.[state.language==='id'?0:1]||t('notifications')}
async function load(){loading.value=true;error.value='';try{const data=await api('/notifications');if(mounted)items.value=data.items||[]}catch(exception){if(mounted)error.value=exception.message}finally{if(mounted)loading.value=false}}
function open(item){emit('navigate');go(item.url)}
onMounted(load);
onBeforeUnmount(()=>{mounted=false});
</script>
<template>
<strong>{{t('notifications')}}</strong>
<p class="notification-caption">{{state.language==='id'?'Aktivitas ujian terkini':'Current assessment activity'}}</p>
<p v-if="loading" role="status">{{t('loading')}}</p>
<div v-else-if="error"><p role="alert">{{error}}</p><button class="button secondary" @click="load">{{state.language==='id'?'Coba lagi':'Try again'}}</button></div>
<p v-else-if="!items.length">{{t('noNotifications')}}</p>
<ul v-else class="notification-items">
    <li v-for="item in items" :key="item.id"><a :href="item.url" @click.prevent="open(item)"><span>{{label(item)}}<b v-if="item.count!==null"> · {{item.count}}</b></span><small v-if="item.detail">{{item.detail}}</small></a></li>
</ul>
</template>
<style scoped>
.notification-caption{font-size:1rem!important;margin:.5rem 0 1rem}
.notification-items{list-style:none;margin:0;padding:0;max-height:min(55vh,430px);overflow-y:auto;overscroll-behavior:contain}
.notification-items li+li{border-top:1px solid var(--border)}
.notification-items a{display:flex;flex-direction:column;gap:.35rem;padding:.85rem .2rem;color:var(--text);font-size:1rem;line-height:1.5;overflow-wrap:anywhere}
.notification-items a:hover,.notification-items a:focus-visible{color:var(--primary)}
.notification-items small{font-size:.9375rem;color:var(--muted)}
</style>
