<script setup>
import {computed,onMounted,watch,ref,nextTick,onBeforeUnmount} from 'vue';
import {Chart,registerables} from 'chart.js';
import {state} from '../store';
import {wrapChartLabel,chartLabelWidth} from '../chart-labels';
Chart.register(...registerables);
const props=defineProps({scores:{default:()=>[]},radar:Boolean});
const canvas=ref(null),container=ref(null),width=ref(0),chartHeight=ref('336px');let chart,observer,mounted=false,renderVersion=0;
const isRadar=computed(()=>props.radar&&width.value>=420);
const scoreName=score=>state.language==='en'?(score.name_en||score.name||score.code):(score.name||score.code);
const scoreValue=score=>Number(score.normalized_score??score.score)||0;
function wrapLabel(value,limit=24){return wrapChartLabel(value,limit,text=>[...text].length)}
async function render(){
    const version=++renderVersion;
    // Canvas metrics cached before a webfont loads can clip the first label glyph.
    if(document.fonts)await document.fonts.ready;
    if(!mounted||!canvas.value||version!==renderVersion)return;
    const tokens=getComputedStyle(document.documentElement),token=(name,fallback)=>tokens.getPropertyValue(name).trim()||fallback;
    const size=Math.max(16,parseFloat(tokens.fontSize)||16),color=token('--muted','#B5C9BE'),grid=token('--border','#35574A'),accent=token('--color-accent','#BEF78F');
    const font={size,family:'Plus Jakarta Sans, Segoe UI, sans-serif'};
    const context=canvas.value.getContext('2d');context.font=size+'px '+font.family;
    const availableWidth=canvas.value.parentElement.clientWidth,labelWidth=chartLabelWidth(availableWidth);
    const labels=props.scores.map(score=>wrapChartLabel(scoreName(score),labelWidth,text=>context.measureText(text).width));
    chartHeight.value=isRadar.value?'28rem':Math.max(336,labels.reduce((sum,lines)=>sum+Math.max(64,lines.length*size*1.5+24),64))+'px';
    await nextTick();
    if(!mounted||!canvas.value||version!==renderVersion)return;
    chart?.destroy();
    chart=new Chart(canvas.value,{
        type:isRadar.value?'radar':'bar',
        data:{labels,datasets:[{label:state.language==='id'?'Skor (%)':'Score (%)',data:props.scores.map(scoreValue),backgroundColor:isRadar.value?token('--blue-soft','rgba(190,247,143,.15)'):accent,borderColor:accent,borderWidth:2,pointBackgroundColor:accent,borderRadius:6,maxBarThickness:32}]},
        options:{responsive:true,maintainAspectRatio:false,indexAxis:isRadar.value?'x':'y',animation:matchMedia('(prefers-reduced-motion: reduce)').matches?false:undefined,layout:{padding:{left:16,right:12,top:16,bottom:8}},plugins:{legend:{display:false},tooltip:{padding:12,cornerRadius:8,titleFont:font,bodyFont:font,callbacks:{title:items=>wrapLabel(scoreName(props.scores[items[0]?.dataIndex]||{}),width.value<360?20:36),label:item=>item.dataset.label+': '+scoreValue(props.scores[item.dataIndex])+'%'}}},scales:isRadar.value?{r:{beginAtZero:true,max:100,ticks:{display:false,stepSize:25},grid:{color:grid},angleLines:{color:grid},pointLabels:{color,font,padding:12}}}:{x:{beginAtZero:true,max:100,border:{display:false},grid:{color:grid},ticks:{color,font,stepSize:25,maxTicksLimit:width.value<360?3:5}},y:{grid:{display:false},ticks:{color,font,autoSkip:false,padding:10}}}}
    });
}
onMounted(()=>{mounted=true;observer=new ResizeObserver(entries=>{const nextWidth=Math.round(entries[0].contentRect.width);if(nextWidth!==width.value){width.value=nextWidth;nextTick(render)}});observer.observe(container.value);render()});
watch([()=>props.scores,()=>props.radar,()=>state.textSize,()=>state.dark,()=>state.language],()=>nextTick(render),{deep:true});
onBeforeUnmount(()=>{mounted=false;observer?.disconnect();chart?.destroy()});
</script>
<template>
<div ref="container" class="score-visualization">
    <div v-if="scores.length" class="score-chart-canvas" :style="{height:chartHeight}"><canvas ref="canvas" :aria-label="state.language==='id'?'Grafik skor kompetensi. Nilai tersedia pada rincian di bawah.':'Competency scores chart. Values are available in the details below.'" role="img"></canvas></div>
    <p v-else class="score-chart-empty">{{state.language==='id'?'Skor kompetensi belum tersedia.':'Competency scores are not available yet.'}}</p>
    <details v-if="scores.length" class="score-chart-details"><summary>{{state.language==='id'?'Lihat nilai kompetensi':'View competency values'}}</summary><dl><div v-for="(score,i) in scores" :key="score.competency_id||score.code||i"><dt>{{scoreName(score)}}</dt><dd>{{scoreValue(score)}}%</dd></div></dl></details>
</div>
</template>
<style scoped>
.score-visualization{min-width:0;max-width:100%;padding:1rem}.score-chart-canvas{position:relative;min-width:0;max-width:100%}.score-chart-canvas canvas{max-width:100%}.score-chart-details{border-top:1px solid var(--border);padding-top:.5rem;color:var(--text)}.score-chart-details summary{cursor:pointer;padding:.5rem 0;min-height:44px;font-size:1.0625rem;font-weight:600}.score-chart-details dl{margin:.5rem 0 0}.score-chart-details dl>div{display:flex;justify-content:space-between;gap:1rem;padding:.75rem 0;border-top:1px solid var(--border);font-size:1.0625rem;line-height:1.6}.score-chart-details dt{overflow-wrap:anywhere}.score-chart-details dd{margin:0;font-weight:700;flex-shrink:0;font-variant-numeric:tabular-nums}.score-chart-empty{padding:2rem 1rem;text-align:center;color:var(--muted);font-size:1.125rem;line-height:1.7}@media(max-width:600px){.score-visualization{padding:.75rem}}
</style>
