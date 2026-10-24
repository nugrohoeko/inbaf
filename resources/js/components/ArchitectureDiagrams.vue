<script setup>
import {computed} from 'vue';
import {state} from '../store';
import Icon from './Icon.vue';
const id=computed(()=>state.language==='id');
const tr=(a,b)=>id.value?a:b;
const types=[{code:'practice',icon:'BookOpen',en:'Practice Test',name:'Tes Simulasi',detail:['Latihan dan pembahasan sesuai izin','Practice and permitted answer review']},{code:'placement',icon:'Target',en:'Placement Test',name:'Tes Penempatan',detail:['Level awal dan rekomendasi belajar','Starting level and learning recommendations']},{code:'certification',icon:'BadgeCheck',en:'Certification Test',name:'Tes Sertifikasi',detail:['Penilaian formal dan validasi hasil','Formal assessment and result validation']}];
const participant=computed(()=>[
 ['LogIn',tr('Masuk dan atur ukuran teks','Sign in and choose text size')],['LayoutDashboard',tr('Pilih jenis tes dan paket','Choose test type and package')],['ClipboardCheck',tr('Baca kebijakan dan periksa perangkat','Read policy and check devices')],['PenLine',tr('Kerjakan, simpan, dan kirim jawaban','Answer, autosave, and submit')],['ChartNoAxesCombined',tr('Lihat hasil dan rencana belajar','View results and learning plan')]
]);
const admin=computed(()=>[
 ['Settings2',tr('Atur kebijakan per jenis tes','Configure each test type')],['LibraryBig',tr('Kelola versi, izin, dan kerahasiaan soal','Manage question versions, access, and confidentiality')],['Layers',tr('Susun paket, tingkat kesulitan, dan jadwal','Build packages, difficulty selection, and schedule')],['ClipboardCheck',tr('Publikasikan dan nilai jawaban manual','Publish and assess manual responses')],['ShieldCheck',tr('Finalisasi hasil dan audit sertifikat','Finalize results and audit certificates')]
]);
</script>
<template>
<div class="architecture-page">
 <header class="page-heading"><div><span class="eyebrow">INBAF / SYSTEM MAP</span><h1>{{tr('Satu platform. Tiga tujuan.','One platform. Three purposes.')}}</h1><p>{{tr('Arsitektur dan alur kerja asesmen Bahasa Indonesia.','The architecture and workflows behind Indonesian language assessment.')}}</p></div></header>
 <section class="panel architecture-panel" aria-labelledby="architecture-title">
  <div class="panel-heading"><div><h2 id="architecture-title">{{tr('Arsitektur sistem','System architecture')}}</h2><p>{{tr('Bank soal terpusat dengan kebijakan, hasil, dan kesempatan yang dikelola per jenis tes.','A shared bank with policies, results, and attempt allowances managed by test type.')}}</p></div><Icon name="Workflow" :size="30"/></div>
  <div class="architecture-map">
   <div class="map-node platform-node"><Icon name="Globe2" :size="30"/><strong>INBAF Platform</strong><span>{{tr('Autentikasi, aksesibilitas, dan kontrol akses','Authentication, accessibility, and access control')}}</span></div>
   <div class="map-arrow" aria-hidden="true">↓</div>
   <div class="map-node bank-node"><Icon name="LibraryBig" :size="30"/><strong>Shared Question Bank</strong><span>{{tr('Versi • izin penggunaan • kerahasiaan • batas pemakaian','Versions • permissions • confidentiality • usage limits')}}</span></div>
   <div class="map-arrow" aria-hidden="true">↓</div>
   <div class="map-types"><div v-for="type in types" :key="type.code" class="map-node" :class="type.code"><Icon :name="type.icon" :size="30"/><strong>{{type.en}}</strong><span>{{id?type.name:type.detail[1]}}</span><small v-if="id">{{type.detail[0]}}</small></div></div>
   <div class="map-arrow" aria-hidden="true">↓</div>
   <div class="snapshot-note"><Icon name="LockKeyhole" :size="22"/><span>{{tr('Snapshot saat mulai: soal, kunci, bobot, kebijakan, dan ambang level tetap untuk sesi tersebut.','Snapshot at start: questions, keys, weights, policies, and level thresholds stay fixed for that attempt.')}}</span></div>
   <div class="map-arrow" aria-hidden="true">↓</div>
   <div class="map-pair"><div class="map-node"><Icon name="Calculator" :size="28"/><strong>Scoring Engine</strong><span>{{tr('Otomatis + manual • metode dan skala terkonfigurasi','Automatic + manual • configurable method and scale')}}</span></div><div class="pair-arrow" aria-hidden="true">→</div><div class="map-node"><Icon name="ChartNoAxesCombined" :size="28"/><strong>Results &amp; Analytics</strong><span>{{tr('Riwayat per jenis tes • kompetensi • rekomendasi','History by test type • competencies • recommendations')}}</span></div></div>
   <div class="map-arrow certification-branch">↓ <span>{{tr('Khusus sertifikasi yang memenuhi syarat','Eligible certification attempts only')}}</span></div>
   <div class="map-node certification"><Icon name="QrCode" :size="30"/><strong>Certificate &amp; QR Verification</strong><span>{{tr('PDF bertanda tangan HMAC • verifikasi publik • kedaluwarsa • pencabutan • audit','HMAC-signed PDF • public verification • expiry • revocation • audit')}}</span></div>
  </div>
 </section>
 <section v-for="(flow,index) in [participant,admin]" :key="index" class="panel architecture-panel" :aria-labelledby="'flow-title-'+index">
  <div class="panel-heading"><div><h2 :id="'flow-title-'+index">{{index===0?tr('Alur peserta','Participant journey'):tr('Alur Superadmin','Superadmin workflow')}}</h2><p>{{index===0?tr('Dari persiapan hingga hasil yang dapat digunakan untuk belajar.','From preparation to results that guide learning.'):tr('Dari bank soal hingga hasil final yang dapat diaudit.','From question authoring to auditable final results.')}}</p></div><Icon :name="index===0?'UserRound':'ShieldCheck'" :size="28"/></div>
  <ol class="workflow-steps"><li v-for="(step,i) in flow" :key="i"><span class="step-number">{{i+1}}</span><Icon :name="step[0]" :size="27"/><strong>{{step[1]}}</strong><Icon v-if="i<flow.length-1" class="step-next" name="ArrowRight" :size="22" aria-hidden="true"/></li></ol>
  <p v-if="index===0" class="workflow-note">{{tr('Simulasi menyimpan riwayat latihan. Placement menyediakan laporan penempatan. Sertifikasi menerbitkan sertifikat hanya setelah penilaian dan persyaratan selesai.','Practice keeps a learning history. Placement provides a placement report. Certification issues a certificate after assessment and eligibility checks are complete.')}}</p>
 </section>
 <p class="architecture-disclaimer">{{tr('A1–C2 adalah klasifikasi internal INBAF. Diagram ini tidak menyatakan pengakuan pemerintah atau kesetaraan dengan asesmen eksternal. Adaptive testing merupakan arah pengembangan, belum digunakan dalam pemilihan soal saat ini.','A1–C2 are internal INBAF classifications. This diagram makes no claim of government recognition or equivalence to external assessments. Adaptive testing is a future extension and is not used for current question selection.')}}</p>
</div>
</template>
<style scoped>
.architecture-page{max-width:100rem;margin:auto}.architecture-panel{margin-bottom:1.5rem}.architecture-panel .panel-heading{align-items:start}.architecture-panel h2{font-size:1.6rem}.architecture-panel .panel-heading p{max-width:65rem;font-size:1.1rem;line-height:1.6}.architecture-map{padding:1.5rem;max-width:82rem;margin:auto}.map-node{background:var(--surface-alt);border:1px solid var(--border);border-radius:1rem;padding:1.5rem;display:flex;align-items:center;justify-content:center;text-align:center;flex-direction:column;gap:.65rem;min-width:0}.map-node strong{font-size:1.35rem;line-height:1.5}.map-node span,.map-node small{font-size:1rem;line-height:1.6;color:var(--text)}.platform-node{background:var(--blue-soft)}.map-types{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.2rem}.practice{border-top:4px solid #2455c7}.placement{border-top:4px solid #087f8c}.certification{border-top:4px solid #8b5b12}.map-arrow{text-align:center;color:var(--muted);font-size:2rem;line-height:1.5;padding:.3rem}.certification-branch span{font-size:1rem;display:block}.snapshot-note{display:flex;align-items:center;justify-content:center;gap:.8rem;font-size:1rem;line-height:1.6;padding:1rem;border:1px dashed var(--border);border-radius:.8rem}.snapshot-note svg{flex-shrink:0}.map-pair{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:1rem}.pair-arrow{font-size:2rem;color:var(--muted)}.workflow-steps{list-style:none;padding:1rem 1.5rem 1.5rem;margin:0;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:1.2rem}.workflow-steps li{position:relative;background:var(--surface-alt);border:1px solid var(--border);border-radius:1rem;display:flex;flex-direction:column;align-items:start;gap:1rem;padding:1.25rem}.workflow-steps strong{font-size:1.05rem;line-height:1.6}.step-number{font-size:1rem;background:var(--blue-soft);color:var(--blue);border-radius:50%;width:2rem;height:2rem;display:grid;place-items:center;font-weight:700}.step-next{position:absolute;right:-1.3rem;top:50%;z-index:1}.workflow-note{padding:0 1.5rem 1.5rem;font-size:1.05rem;line-height:1.7}.architecture-disclaimer{font-size:1rem;line-height:1.7;color:var(--muted);padding:.5rem}
@media(max-width:1100px){.workflow-steps{grid-template-columns:1fr}.workflow-steps li{min-height:5rem;display:grid;grid-template-columns:2rem 2rem minmax(0,1fr);align-items:center}.step-next{right:50%;top:auto;bottom:-1.35rem;transform:rotate(90deg)}}@media(max-width:700px){.map-types,.map-pair{grid-template-columns:1fr}.pair-arrow{transform:rotate(90deg);text-align:center}.architecture-map{padding:1rem}.map-node{padding:1.2rem}.workflow-steps{padding:1rem}.architecture-panel h2{font-size:1.4rem}}
</style>
<style scoped>
@media(max-width:600px){.workflow-steps li{grid-template-columns:2rem minmax(0,1fr);gap:.75rem;padding:1rem}.workflow-steps strong{grid-column:1/-1}}
</style>
