<script setup>
import {state,t,setTextSize} from '../store';
defineProps({compact:{type:Boolean,default:false}});
</script>
<template>
<label class="text-size-control" :class="{compact}">
    <span aria-hidden="true" class="text-size-symbol">Aa</span><span class="text-size-label">{{t('textSize')}}</span>
    <select :value="state.textSize" @change="setTextSize($event.target.value)" :aria-label="t('textSize')" :aria-busy="state.textSizeSaving">
        <option value="small">{{t('textSmall')}}</option><option value="normal">{{t('textNormal')}}</option><option value="large">{{t('textLarge')}}</option><option value="extra_large">{{t('textExtraLarge')}}</option>
    </select>
</label>
</template>
