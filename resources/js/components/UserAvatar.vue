<script setup>
import {computed, ref, watch} from 'vue';
import {state} from '../store';
const props = defineProps({size: {type: Number, default: 56}});
const failed = ref(false);
const initials = computed(() => String(state.user?.name || 'INBAF').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0]).join('').toUpperCase());
watch(() => state.user?.avatar_url, () => {failed.value = false;});
</script>
<template><span class="user-avatar" :style="{'--user-avatar-size': props.size + 'px'}"><img v-if="state.user?.avatar_url && !failed" :src="state.user.avatar_url" alt="" @error="failed = true"><span v-else aria-hidden="true">{{ initials }}</span></span></template>
<style scoped>
.user-avatar{display:inline-flex;width:var(--user-avatar-size);height:var(--user-avatar-size);align-items:center;justify-content:center;flex-shrink:0;border:2px solid var(--surface);border-radius:50%;overflow:hidden;background:#e3f4ed;color:#086b63;font-weight:650;box-shadow:0 3px 14px #0a4b3712}.user-avatar>img{display:block;width:100%;height:100%;object-fit:cover}.dark .user-avatar{background:#173c38;color:#8cdec4}
</style>
