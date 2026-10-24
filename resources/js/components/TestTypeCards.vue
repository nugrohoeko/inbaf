<script setup>
import {computed} from 'vue';
import {state,t,go,testTypeCodes,testTypeName,testTypeCode,testPolicy,testDuration} from '../store';
import Icon from './Icon.vue';
const props=defineProps({packages:{type:Array,default:()=>[]},selected:{type:String,default:''},selectOnly:Boolean});
const emit=defineEmits(['select']);
const cards=computed(()=>testTypeCodes.map(code=>({code,type:state.test_types.find(row=>row.code===code),packages:props.packages.filter(row=>testTypeCode(row)===code)})));
function choose(code){emit('select',code);if(!props.selectOnly)go('/tests?test_type='+code)}
function detail(card){const durations=[...new Set(card.packages.map(testDuration))];return durations.length===1?durations[0]:durations.length?Math.min(...card.packages.filter(p=>testPolicy(p).timer_enabled!==false).map(p=>Number(p.duration_minutes)||0))+'+ '+t('duration'):t('noPackages')}
function competencies(card){return new Set(card.packages.flatMap(p=>(p.sections||[]).map(s=>s.competency_id))).size||Math.max(0,...card.packages.map(p=>p.sections_count||0))}
const buttons={practice:'startPractice',placement:'startPlacement',certification:'startCertification'};
</script>
<template>
<section class="test-type-selection" aria-labelledby="test-types-title">
    <div class="section-heading"><div><div class="eyebrow">INBAF · {{t('testTypes')}}</div><h2 id="test-types-title">{{t('chooseTest')}}</h2><p>{{t('chooseTestSub')}}</p></div></div>
    <div class="test-type-grid">
        <article v-for="card in cards" :key="card.code" class="test-type-card" :class="[card.code,{selected:selected===card.code,unavailable:!card.type||card.type.active===false}]">
            <div class="test-type-card-top"><span class="type-kicker">{{t(card.code+'Subtitle')}}</span><Icon v-if="selected===card.code" name="CircleCheck" :size="24"/></div>
            <svg class="test-type-art" viewBox="0 0 240 132" aria-hidden="true" focusable="false">
                <ellipse cx="120" cy="118" rx="73" ry="9" fill="currentColor" opacity=".08"/><circle cx="120" cy="65" r="56" fill="currentColor" opacity=".07"/>
                <g v-if="card.code==='practice'" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M120 36C96 23 76 29 62 36V102C84 92 102 97 120 108C138 97 156 92 178 102V36C158 27 141 26 120 36Z" fill="var(--surface)"/><path d="M120 36V108M76 49C89 44 99 47 108 51M76 63C89 58 99 61 108 65M76 77C89 72 99 75 108 79M132 51L143 62L164 43" fill="none"/><path d="M137 80H162" opacity=".35"/></g>
                <g v-else-if="card.code==='placement'" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M62 105V32M62 105H184" opacity=".35"/><rect x="79" y="78" width="21" height="26" rx="4" fill="currentColor" opacity=".2" stroke="none"/><rect x="112" y="59" width="21" height="45" rx="4" fill="currentColor" opacity=".35" stroke="none"/><rect x="145" y="40" width="21" height="64" rx="4" fill="currentColor" opacity=".55" stroke="none"/><path d="M78 65L113 44L144 26L173 18M156 17H174V35" fill="none"/><circle cx="174" cy="74" r="21" fill="var(--surface)"/><circle cx="174" cy="74" r="12" fill="none"/><circle cx="174" cy="74" r="3" fill="currentColor" stroke="none"/></g>
                <g v-else stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect x="62" y="23" width="112" height="79" rx="7" fill="var(--surface)"/><path d="M78 38H158M94 53H143M79 83H116" opacity=".35"/><path d="M145 87L137 118L151 112L161 121L168 89" fill="var(--surface)"/><circle cx="153" cy="80" r="22" fill="var(--surface)"/><circle cx="153" cy="80" r="15" fill="currentColor" opacity=".13" stroke="none"/><path d="M144 80L151 87L163 73" fill="none"/></g>
                <g fill="currentColor" opacity=".4"><circle cx="43" cy="58" r="3"/><circle cx="197" cy="39" r="3"/><path d="M193 100V111M187.5 105.5H198.5" stroke="currentColor" stroke-width="2"/></g>
            </svg>
            <h3>{{testTypeName(card.code)}}</h3><p>{{(state.language==='en'?card.type?.description_en:card.type?.description)||t(card.code+'Description')}}</p>
            <div class="test-type-facts"><span><Icon name="Clock3" :size="18"/>{{detail(card)}}</span><span><Icon name="Shapes" :size="18"/>{{competencies(card)}} {{t('skills')}}</span><span><Icon name="Layers" :size="18"/>{{card.packages.length}} {{t('availablePackages')}}</span></div>
            <div class="test-type-requirements"><Icon :name="card.code==='certification'?'BadgeCheck':'BookOpenCheck'" :size="18"/><span>{{card.code==='certification'?t('eligibleCertificate'):t('noOfficialCertificate')}}</span></div>
            <button class="button full type-start" :disabled="!card.type||card.type.active===false" :aria-pressed="selectOnly?selected===card.code:undefined" @click="choose(card.code)">{{!card.type||card.type.active===false?t('typeUnavailable'):t(buttons[card.code])}}<Icon name="ArrowUpRight" :size="19"/></button>
        </article>
    </div>
</section>
</template>
<style scoped>
.test-type-selection{min-width:0}.section-heading{align-items:flex-start;gap:16px}.section-heading h2{font-size:clamp(1.5rem,2.5vw,1.75rem);line-height:1.4}.section-heading p{font-size:1.125rem;max-width:54rem}.test-type-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;align-items:stretch}.test-type-card{display:flex;flex-direction:column;min-width:0;padding:24px;border:1px solid var(--border);border-radius:16px;background:var(--surface);color:var(--color-accent);overflow:visible}.test-type-card.selected{border-color:var(--color-accent);box-shadow:inset 0 0 0 1px var(--color-accent)}.test-type-card.unavailable{opacity:1}.test-type-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;min-height:48px}.type-kicker{font-size:1.0625rem;line-height:1.6;color:var(--muted);overflow-wrap:anywhere}.test-type-card-top>svg{flex-shrink:0}.test-type-art{width:100%;max-width:240px;min-height:132px;align-self:center;flex-shrink:0;color:var(--color-accent)}.test-type-card h3{font-size:1.5rem;line-height:1.4;margin-top:16px;overflow-wrap:anywhere}.test-type-card>p{font-size:1.125rem;line-height:1.8;color:var(--muted);min-height:0;flex:1;margin:12px 0 20px;overflow-wrap:anywhere}.test-type-facts{display:grid;gap:12px;min-width:0;color:var(--text);font-size:1.0625rem}.test-type-facts>span{display:flex;align-items:flex-start;gap:12px;line-height:1.6;overflow-wrap:anywhere}.test-type-facts svg{flex-shrink:0;margin-top:4px;color:var(--color-accent)}.test-type-requirements{display:flex;align-items:flex-start;gap:12px;margin:20px 0;padding-top:20px;border-top:1px solid var(--border);font-size:1.0625rem;color:var(--muted);line-height:1.6}.test-type-requirements svg{flex-shrink:0;margin-top:4px}.test-type-requirements span{min-width:0;overflow-wrap:anywhere}.test-type-card>.type-start{min-height:48px;white-space:normal;font-size:1.0625rem;padding:12px 16px;line-height:1.5;margin-top:auto}.test-type-card>.type-start:focus-visible{outline:3px solid var(--color-accent);outline-offset:4px}
@media(max-width:1200px){.test-type-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),1fr))}}
@media(max-width:600px){.test-type-grid{grid-template-columns:minmax(0,1fr);gap:16px}.test-type-card{padding:20px}.section-heading p{max-width:none}.test-type-card-top{min-height:0}}
.test-type-selection .test-type-card{--type-color:var(--color-accent);--type-soft:var(--blue-soft)}.test-type-selection .test-type-card>.type-start{background:var(--color-primary)!important;color:var(--color-primary-contrast);border-color:transparent}.test-type-selection .test-type-card>.type-start:hover{background:var(--color-primary-hover)!important}
</style>
